<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Setting;
use App\Services\Messaging\DeliveryWebhookHandler;
use App\Services\Messaging\InboundMessageService;
use App\Services\Messaging\QuoConversationLinker;
use App\Services\Messaging\WebhookSignatureVerifier;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound webhook for OpenPhone/Quo. Verifies the signature, stores every
 * inbound message, routes message.received events to the inbound service
 * (which handles customer matching + opt-out), and routes every other event
 * (delivery status for messages WE sent) to DeliveryWebhookHandler.
 */
class OpenPhoneWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        WebhookSignatureVerifier $verifier,
        InboundMessageService $inbound,
        QuoConversationLinker $linker,
        DeliveryWebhookHandler $delivery,
    ): JsonResponse {
        $rawBody = $request->getContent();
        $secret = Setting::get('openphone_webhook_secret');

        // Verify signature when a secret is configured. In production a secret is
        // required; locally (no secret) we allow but log, so dev testing works.
        if (filled($secret)) {
            $ok = $verifier->verify($request->header('openphone-signature'), $rawBody, $secret);
            if (! $ok) {
                Log::warning('OpenPhone webhook: invalid signature');

                return response()->json(['error' => 'invalid signature'], 401);
            }
        } elseif (app()->environment('production')) {
            Log::error('OpenPhone webhook: no signing secret configured in production');

            return response()->json(['error' => 'not configured'], 503);
        }

        $payload = $request->json()->all();
        $type = $payload['type'] ?? null;
        $object = data_get($payload, 'data.object', []);

        // Capture the Quo conversation link for the matched customer on ANY
        // message event (inbound or outbound) — whichever of from/to is a known
        // customer is the one the conversation belongs to.
        $this->captureConversationLink($linker, $object);

        if ($type === 'message.received' || (data_get($object, 'direction') === 'incoming')) {
            $inbound->handle([
                'from_phone' => data_get($object, 'from'),
                'to_phone' => is_array(data_get($object, 'to')) ? data_get($object, 'to.0') : data_get($object, 'to'),
                'body' => data_get($object, 'text') ?? data_get($object, 'body'),
                'provider_message_id' => data_get($object, 'id'),
                'received_at' => data_get($object, 'createdAt'),
                'raw_payload' => $payload,
            ]);
        } else {
            // Everything else (message.delivered/message.failed/etc.) is a
            // status update for a message WE sent — correlate + alert on failure.
            $delivery->handle($object, $type);
        }

        return response()->json(['received' => true]);
    }

    /**
     * Persist the conversation deep link from a message object. The object
     * carries phoneNumberId + conversationId; one of from/to is the customer
     * (the other is our Quo number, which is not a Customer row).
     */
    private function captureConversationLink(QuoConversationLinker $linker, array $object): void
    {
        if (blank(data_get($object, 'conversationId')) || blank(data_get($object, 'phoneNumberId'))) {
            return;
        }

        $candidates = [
            data_get($object, 'from'),
            is_array(data_get($object, 'to')) ? data_get($object, 'to.0') : data_get($object, 'to'),
        ];

        foreach ($candidates as $phone) {
            $e164 = PhoneNumber::toE164($phone);
            if ($e164 === null) {
                continue;
            }

            $customer = Customer::where('phone_e164', $e164)->first();
            if ($customer) {
                $linker->captureFromObject($customer, $object);

                return;
            }
        }
    }
}
