<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Services\JobLifecycle;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripePayoutResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Stripe webhook — two independent event types handled, both 2xx'd once the
 * signature is valid so Stripe stops retrying (an unresolvable event is
 * logged, never re-queued forever):
 *
 *  - checkout.session.completed: fires when a customer pays a Stripe Payment
 *    Link we texted. Records the payment on the job and auto-confirms it
 *    received — Stripe's own event is what closes that loop, no bank
 *    matching needed for it.
 *
 *  - payout.paid: fires when Stripe actually pays out a batch of card
 *    charges to our bank account (1-2 days after the charges themselves).
 *    Delegates to StripePayoutResolver, which upserts a StripePayout row and
 *    attempts to resolve exactly which job_payments it covers via a
 *    follow-up Stripe call — see that class and StripePayout's migration
 *    doc comment. A failed resolution is recorded on the row, NOT retried by
 *    Stripe's own webhook redelivery — stripe:resolve-payouts (scheduled,
 *    not yet built) owns retrying it. Until a payout resolves, the existing
 *    manual bank-matching flow (BankTransactionsTable::isStripeDeposit and
 *    friends) is the fallback — unchanged, still fully functional.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeClient $stripe, JobLifecycle $lifecycle, StripePayoutResolver $payoutResolver): JsonResponse
    {
        $payload = $request->getContent();

        if (! $stripe->verifyWebhook($payload, $request->header('Stripe-Signature'))) {
            Log::warning('Stripe webhook: invalid signature');

            return response()->json(['error' => 'invalid signature'], 400);
        }

        $event = json_decode($payload, true) ?: [];
        $type = $event['type'] ?? '';

        if ($type === 'payout.paid') {
            return $this->handlePayoutPaid($event['data']['object'] ?? [], $payoutResolver);
        }

        if ($type === 'checkout.session.completed') {
            return $this->handleCheckoutSessionCompleted($event['data']['object'] ?? [], $lifecycle);
        }

        return response()->json(['ignored' => $type ?: null]);
    }

    private function handleCheckoutSessionCompleted(array $session, JobLifecycle $lifecycle): JsonResponse
    {
        if (($session['payment_status'] ?? '') !== 'paid') {
            return response()->json(['ignored' => 'not paid']);
        }

        $job = $this->resolveJob($session);
        if (! $job) {
            Log::warning('Stripe webhook: paid session matched no job', [
                'payment_link' => $session['payment_link'] ?? null,
                'session' => $session['id'] ?? null,
            ]);

            return response()->json(['ignored' => 'no job']);
        }

        // Stripe's payment_intent id (fallback: the session id) is the reference.
        $ref = $session['payment_intent'] ?? ($session['id'] ?? null);
        $lifecycle->recordStripeWebhookPayment($job, is_string($ref) ? $ref : null);

        return response()->json(['ok' => true, 'job' => $job->id]);
    }

    /**
     * Never fails the webhook response — StripePayoutResolver records any
     * Stripe-call failure on the row itself (resolution_status =
     * resolution_failed) rather than throwing, so a Stripe outage at
     * resolution time doesn't turn into an endless webhook-retry loop; the
     * scheduled retry command picks it back up instead.
     */
    private function handlePayoutPaid(array $payoutObject, StripePayoutResolver $payoutResolver): JsonResponse
    {
        if (($payoutObject['id'] ?? null) === null) {
            Log::warning('Stripe webhook: payout.paid with no payout id', ['payout' => $payoutObject]);

            return response()->json(['ignored' => 'no payout id']);
        }

        $payout = $payoutResolver->handlePayoutPaid($payoutObject);

        return response()->json(['ok' => true, 'payout' => $payout->stripe_payout_id, 'resolution_status' => $payout->resolution_status]);
    }

    /**
     * Which job this paid session settles: the Payment Link id Stripe returns
     * (`session.payment_link`, stored on the job as `stripe_invoice_id` when we
     * created the link) is the reliable key; the link's metadata job_id is a
     * fallback for links created with it.
     */
    private function resolveJob(array $session): ?Job
    {
        $linkId = $session['payment_link'] ?? null;
        if (is_string($linkId) && $linkId !== '') {
            if ($job = Job::where('stripe_invoice_id', $linkId)->first()) {
                return $job;
            }
        }

        $jobId = $session['metadata']['job_id'] ?? null;

        return $jobId ? Job::find($jobId) : null;
    }
}
