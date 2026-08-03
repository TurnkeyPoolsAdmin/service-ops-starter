<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Podium\PodiumMessageService;
use App\Services\Podium\PodiumSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound webhook for Podium message events (the sales team's customer channel).
 * Verifies the signature, then records each sent/received/failed message keyed by
 * the customer's phone so intake can show the last conversation when we take over.
 *
 * Podium has no read API; this webhook is the only source of message history.
 * Payload shape: { metadata: { eventType }, data: { body, createdAt,
 * conversation: { uid, channel: { type, identifier } } } }.
 * Reference: https://docs.podium.com/docs/sync-messages-from-podium-conversations
 */
class PodiumWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PodiumSignatureVerifier $verifier,
        PodiumMessageService $service,
    ): JsonResponse {
        $rawBody = $request->getContent();
        $secret = Setting::get('podium_webhook_secret');

        // Verify when a secret is configured. In production a secret is required;
        // locally (no secret) we allow but log, so dev testing works.
        if (filled($secret)) {
            $ok = $verifier->verify(
                $request->header('Podium-Signature'),
                $request->header('podium-timestamp'),
                $rawBody,
                $secret,
            );
            if (! $ok) {
                Log::warning('Podium webhook: invalid signature');

                return response()->json(['error' => 'invalid signature'], 401);
            }
        } elseif (app()->environment('production')) {
            Log::error('Podium webhook: no signing secret configured in production');

            return response()->json(['error' => 'not configured'], 503);
        }

        $payload = $request->json()->all();
        $eventType = (string) (data_get($payload, 'metadata.eventType')
            ?? data_get($payload, 'metadata.event_type', ''));

        // We only care about message events; ack anything else (e.g. call.*).
        if (! str_starts_with($eventType, 'message.')) {
            return response()->json(['received' => true, 'ignored' => $eventType]);
        }

        // sent/failed originate from the business (sales); received is from the customer.
        $isOutbound = $eventType === 'message.sent' || $eventType === 'message.failed';

        // The conversation channel identifies the consumer for BOTH directions.
        $service->record([
            'event_type' => $eventType,
            'direction' => $isOutbound ? 'outbound' : 'inbound',
            'channel_type' => data_get($payload, 'data.conversation.channel.type'),
            'contact_identifier' => data_get($payload, 'data.conversation.channel.identifier'),
            'conversation_uid' => data_get($payload, 'data.conversation.uid'),
            'body' => data_get($payload, 'data.body'),
            'provider_message_id' => data_get($payload, 'data.uid'),
            'occurred_at' => data_get($payload, 'data.createdAt'),
            'raw_payload' => $payload,
        ]);

        return response()->json(['received' => true]);
    }
}
