<?php

namespace App\Services\Messaging;

use App\Models\ScheduledMessage;
use App\Services\Telegram\MessageDeliveryFailedAlert;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Support\Facades\Log;

/**
 * Handles OpenPhone/Quo webhook events about the eventual fate of a message WE
 * sent — as opposed to InboundMessageService, which handles messages a
 * customer sent us. `status`/`sent_at` on ScheduledMessage only ever reflect
 * the synchronous API response (did OpenPhone accept the send?), never actual
 * carrier delivery — that arrives later, async, via this webhook. Correlated
 * back to our row via `provider_message_id` (stamped by SendSmsJob at send
 * time), so anything we didn't send (or an inbound message's own id) is
 * silently ignored.
 */
class DeliveryWebhookHandler
{
    public function __construct(
        private TelegramNotifier $telegram,
        private MessageDeliveryFailedAlert $alertBuilder,
    ) {}

    public function handle(array $object, ?string $eventType): void
    {
        $providerMessageId = data_get($object, 'id');
        if (blank($providerMessageId)) {
            return;
        }

        $message = ScheduledMessage::with(['job', 'customer'])
            ->where('provider_message_id', $providerMessageId)
            ->first();

        if (! $message) {
            return; // Not one of ours (or an inbound message's own id).
        }

        $outcome = $this->resolveOutcome($object, $eventType);

        if ($outcome === 'delivered') {
            if ($message->delivered_at === null) {
                $message->update(['delivered_at' => now()]);
            }

            return;
        }

        if ($outcome === 'failed') {
            if ($message->delivery_failed_at !== null) {
                return; // Already handled — Quo may retry the same webhook.
            }

            $reason = $this->extractReason($object);
            $message->update(['delivery_failed_at' => now(), 'delivery_error' => $reason]);

            Log::warning('OpenPhone delivery webhook: message failed at carrier', [
                'scheduled_message_id' => $message->id,
                'provider_message_id' => $providerMessageId,
                'reason' => $reason,
            ]);

            $this->alert($message, $reason);
        }
    }

    /** @return 'delivered'|'failed'|null */
    private function resolveOutcome(array $object, ?string $eventType): ?string
    {
        if ($eventType === 'message.delivered') {
            return 'delivered';
        }

        if ($eventType === 'message.failed' || $eventType === 'message.undelivered') {
            return 'failed';
        }

        // Fall back to the message object's own status field — covers event
        // types we haven't seen/named yet (e.g. a generic "message.updated").
        $status = strtolower((string) data_get($object, 'status', ''));

        return match ($status) {
            'delivered' => 'delivered',
            'failed', 'undelivered' => 'failed',
            default => null,
        };
    }

    private function extractReason(array $object): string
    {
        $reason = data_get($object, 'error.message')
            ?? data_get($object, 'error')
            ?? data_get($object, 'errorMessage')
            ?? data_get($object, 'status');

        return is_string($reason) && $reason !== '' ? $reason : 'No reason given by provider.';
    }

    private function alert(ScheduledMessage $message, string $reason): void
    {
        if (! $this->telegram->configured()) {
            return;
        }

        $this->telegram->send($this->alertBuilder->build($message, $reason));
    }
}
