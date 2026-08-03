<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\InboundMessage;
use App\Models\Setting;
use App\Services\Messaging\InboundMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound webhook for RingCentral SMS push notifications (subscription delivery).
 *
 * RingCentral webhooks have two request shapes on the same URL:
 *
 *  1. Registration handshake — when a subscription is (re)created, RingCentral
 *     immediately POSTs with a `Validation-Token` header and empty body. We must
 *     echo that token back in the `Validation-Token` response header within 10s.
 *
 *  2. Event notifications — each carries the `Verification-Token` header we set
 *     on the subscription (compared against the stored secret), with the message
 *     in `body` (subject = text, from/to = phone numbers, direction = Inbound).
 *
 * Event filter to register:
 *   /restapi/v1.0/account/~/extension/~/message-store/instant?type=SMS
 * Reference: https://developers.ringcentral.com/guide/notifications/webhooks/receiving
 */
class RingCentralWebhookController extends Controller
{
    public function __invoke(Request $request, InboundMessageService $inbound): JsonResponse
    {
        // 1. Subscription registration handshake — echo the validation token.
        $validationToken = $request->header('Validation-Token');
        if (filled($validationToken)) {
            return response()->json(['received' => true])
                ->header('Validation-Token', $validationToken);
        }

        // 2. Verify the per-subscription token when one is configured. In prod a
        //    token is required; locally (none) we allow but log, so dev works.
        $expected = Setting::get('ringcentral_webhook_token');
        if (filled($expected)) {
            if (! hash_equals($expected, (string) $request->header('Verification-Token'))) {
                Log::warning('RingCentral webhook: invalid verification token');

                return response()->json(['error' => 'invalid token'], 401);
            }
        } elseif (app()->environment('production')) {
            Log::error('RingCentral webhook: no verification token configured in production');

            return response()->json(['error' => 'not configured'], 503);
        }

        $payload = $request->json()->all();
        $message = data_get($payload, 'body', []);

        // Only inbound SMS matter for compliance/opt-out; ack everything else
        // (status callbacks for our own sends, MMS, etc.).
        $isInboundSms = strcasecmp((string) data_get($message, 'direction'), 'Inbound') === 0
            && strcasecmp((string) data_get($message, 'type', 'SMS'), 'SMS') === 0;

        if (! $isInboundSms) {
            return response()->json(['received' => true, 'ignored' => true]);
        }

        $providerMessageId = ($id = data_get($message, 'id')) !== null ? (string) $id : null;

        // Subscriptions can re-deliver; skip a message we've already recorded.
        if ($providerMessageId !== null
            && InboundMessage::where('provider_message_id', $providerMessageId)->exists()) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        $inbound->handle([
            'from_phone' => data_get($message, 'from.phoneNumber'),
            'to_phone' => data_get($message, 'to.0.phoneNumber'),
            'body' => data_get($message, 'subject'),
            'provider_message_id' => $providerMessageId,
            'received_at' => data_get($message, 'creationTime'),
            'raw_payload' => $payload,
        ]);

        return response()->json(['received' => true]);
    }
}
