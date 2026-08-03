<?php

namespace App\Services\Messaging;

use App\Models\Customer;

/**
 * Derives and persists a customer's Quo/OpenPhone inbox conversation deep link.
 *
 * Quo message objects (in both the send response and webhook events) carry a
 * `phoneNumberId` (our Quo line, e.g. "PNK3QamaPL") and a `conversationId`
 * (per-customer, e.g. "CN59e860…"). The web inbox link is:
 *
 *   {inbox_base}/{phoneNumberId}/c/{conversationId}
 *
 * We capture it the first time we successfully exchange a Quo message with a
 * customer so the job view can open the thread for a call or a custom message.
 */
class QuoConversationLinker
{
    public function urlFor(?string $phoneNumberId, ?string $conversationId): ?string
    {
        if (blank($phoneNumberId) || blank($conversationId)) {
            return null;
        }

        $base = rtrim((string) config('glass.quo.inbox_base', 'https://my.quo.com/inbox'), '/');

        return "{$base}/{$phoneNumberId}/c/{$conversationId}";
    }

    /**
     * Store the conversation link on the customer from a Quo message object
     * ({ phoneNumberId, conversationId, ... }). No-op when the object lacks the
     * ids (e.g. a Podium send) or the stored link already matches.
     */
    public function captureFromObject(?Customer $customer, array $object): void
    {
        if (! $customer) {
            return;
        }

        $url = $this->urlFor(
            data_get($object, 'phoneNumberId'),
            data_get($object, 'conversationId'),
        );

        if ($url === null || $customer->quo_conversation_url === $url) {
            return;
        }

        $customer->forceFill(['quo_conversation_url' => $url])->save();
    }
}
