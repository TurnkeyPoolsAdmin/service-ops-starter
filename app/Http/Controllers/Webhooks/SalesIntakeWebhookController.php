<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Intake\SalesIntakeService;
use App\Services\Messaging\SalesIntakeSignatureVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * POST /webhooks/sales-intake — a booked lead pushed in by the sales dashboard.
 * Implements the receiver half of the shared Sales -> Dispatch Intake Contract v1.
 *
 * Status codes are part of the contract, because the SENDER branches on them:
 *   400 headers missing or malformed
 *   401 bad signature, or a timestamp outside the ±300s replay window
 *   422 validation failed (includes an unknown/inactive entity_code) -> mark failed, don't retry
 *   200 this lead already has a job -> duplicate, nothing created, nothing re-sent
 *   201 created
 *   5xx -> the sender retries every minute
 *
 * Auth mirrors the OpenPhone webhook's guard: a configured secret is always
 * verified; production with NO secret is a 503 rather than an open door, while
 * local development (no secret) is allowed so the flow can be exercised by hand.
 */
class SalesIntakeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        SalesIntakeSignatureVerifier $verifier,
        SalesIntakeService $intake,
    ): JsonResponse {
        $rawBody = $request->getContent();
        $secret = Setting::get('sales_intake_secret');

        if (filled($secret)) {
            if ($denial = $this->rejectBadSignature($request, $verifier, $rawBody, (string) $secret)) {
                return $denial;
            }
        } elseif (app()->environment('production')) {
            Log::error('Sales intake webhook: no signing secret configured in production');

            return response()->json(['error' => 'not configured'], 503);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response()->json(['error' => 'invalid json body'], 400);
        }

        try {
            $validated = $intake->validate($payload);
        } catch (ValidationException $e) {
            Log::warning('Sales intake webhook: validation failed', [
                'external_ref' => $payload['external_ref'] ?? null,
                'errors' => $e->errors(),
            ]);

            return response()->json(['errors' => $e->errors()], 422);
        }

        $source = (string) $validated['external_source'];
        $ref = (string) $validated['external_ref'];

        // Idempotency: one job per sales lead, ever. A retry (or the sender's
        // manual "Resend to dispatch") answers 200 and creates nothing.
        if ($existing = $intake->existingJob($source, $ref)) {
            return response()->json([
                'status' => 'duplicate',
                'job_id' => $existing->id,
                'job_number' => $existing->job_number,
            ], 200);
        }

        try {
            $job = $intake->create($validated);
        } catch (UniqueConstraintViolationException $e) {
            // Two pushes for the same lead raced past the check above; the
            // unique index settled it. Answer for the winner.
            if ($winner = $intake->existingJob($source, $ref)) {
                return response()->json([
                    'status' => 'duplicate',
                    'job_id' => $winner->id,
                    'job_number' => $winner->job_number,
                ], 200);
            }

            throw $e;
        }

        // Welcome + Telegram. Best-effort by contract: the job exists either way.
        $intake->runAutomation($job);

        return response()->json([
            'status' => 'created',
            'job_id' => $job->id,
            'job_number' => $job->job_number,
        ], 201);
    }

    /**
     * The auth gate, split out so each contract status code is visible:
     * missing/malformed headers are the sender's bug (400); a stale or wrong
     * signature is a rejection (401). Null means "let it through".
     */
    private function rejectBadSignature(
        Request $request,
        SalesIntakeSignatureVerifier $verifier,
        string $rawBody,
        string $secret,
    ): ?JsonResponse {
        $timestamp = $request->header('X-Sales-Timestamp');
        $signature = $request->header('X-Sales-Signature');

        if (blank($timestamp) || blank($signature) || ! is_numeric($timestamp)) {
            return response()->json(['error' => 'missing or malformed signature headers'], 400);
        }

        if (! $verifier->timestampFresh($timestamp)) {
            Log::warning('Sales intake webhook: timestamp outside the replay window');

            return response()->json(['error' => 'timestamp outside replay window'], 401);
        }

        if (! $verifier->verify($timestamp, $signature, $rawBody, $secret)) {
            Log::warning('Sales intake webhook: invalid signature');

            return response()->json(['error' => 'invalid signature'], 401);
        }

        return null;
    }
}
