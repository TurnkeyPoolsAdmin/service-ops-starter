<?php

namespace App\Services\Telegram;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\ScheduledMessage;

/**
 * Builds the immediate alert posted when a text exhausts its retries and lands
 * in `failed` — i.e. the PROVIDER refused to accept the send at all (bad
 * credentials, dead OAuth token, malformed number, provider outage).
 *
 * This is the send-time twin of MessageDeliveryFailedAlert, which covers the
 * later "provider accepted it but the carrier never delivered it" case. Both
 * exist because the failure modes are different and so are the fixes: this one
 * usually means something on OUR side is broken and every subsequent text will
 * fail too, so it names the provider to point at the culprit (a dead Podium
 * OAuth refresh token looks nothing like a bad Quo API key).
 *
 * Fired once per row from App\Jobs\SendSmsJob when it goes terminal — never on
 * a schedule, and never for the intermediate retries.
 */
class MessageSendFailedAlert
{
    public function build(ScheduledMessage $message, string $provider): string
    {
        $customerName = e($message->customer?->full_name ?? $message->job?->customer_name_snapshot ?? 'Unknown customer');
        $template = e($message->template_key?->label() ?? (string) $message->template_key);
        $phone = e($message->to_phone_e164);
        $providerSafe = e($this->providerLabel($provider));
        $reason = e($message->error_message ?: ($message->error_code ?: 'No reason given by provider.'));

        $lines = [
            '🚫 <b>SMS failed to send</b>',
            '',
            "To: {$customerName} ({$phone})",
            "Template: {$template}",
            "Provider: {$providerSafe}",
            "Reason: {$reason}",
            'Attempts: '.(int) $message->attempt_count,
        ];

        if ($message->job) {
            $url = JobResource::getUrl('view', ['record' => $message->job]);
            $lines[] = "Job: <a href=\"{$url}\">view</a>";
        }

        $lines[] = '';
        $lines[] = 'This text never left the building. Fix the cause, then Retry it in Operations > Outbox / SMS.';

        return implode("\n", $lines);
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'openphone' => 'Quo / OpenPhone',
            'podium' => 'Podium',
            'ringcentral' => 'RingCentral',
            'log' => 'Log (not a real send)',
            default => $provider,
        };
    }
}
