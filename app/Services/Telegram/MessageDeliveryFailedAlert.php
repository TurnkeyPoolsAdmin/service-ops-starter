<?php

namespace App\Services\Telegram;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\ScheduledMessage;

/**
 * Builds the immediate alert posted when OpenPhone/Quo's delivery webhook
 * reports a sent text failed at the carrier — as opposed to a failure at
 * SendSmsJob's send time (already visible on the job/outbox), this is the
 * "the API accepted it but the carrier never delivered it" case that was
 * previously invisible. Fired once per row from
 * App\Services\Messaging\DeliveryWebhookHandler, never on a schedule.
 */
class MessageDeliveryFailedAlert
{
    public function build(ScheduledMessage $message, string $reason): string
    {
        $customerName = e($message->customer?->full_name ?? $message->job?->customer_name_snapshot ?? 'Unknown customer');
        $template = e($message->template_key?->label() ?? (string) $message->template_key);
        $phone = e($message->to_phone_e164);
        $reasonSafe = e($reason);

        $lines = [
            '🚫 <b>SMS delivery failed</b>',
            '',
            "To: {$customerName} ({$phone})",
            "Template: {$template}",
            "Reason: {$reasonSafe}",
        ];

        if ($message->job) {
            $url = JobResource::getUrl('view', ['record' => $message->job]);
            $lines[] = "Job: <a href=\"{$url}\">view</a>";
        }

        $lines[] = '';
        $lines[] = 'The customer likely never received this text — worth a manual resend or call.';

        return implode("\n", $lines);
    }
}
