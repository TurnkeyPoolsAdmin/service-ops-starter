<?php

namespace App\Services\Telegram;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\ScheduledMessage;
use App\Models\Setting;
use App\Support\AlertWindow;
use App\Support\Timezone;
use Illuminate\Support\Collection;

/**
 * The real detector for a silently-dropped long SMS. OpenPhone/Quo accepts the
 * send (202, status=sent) and, for a text a carrier quietly drops (a long UCS-2
 * message), sends NEITHER a message.delivered NOR a message.failed webhook — it
 * just goes silent. A normal text gets its message.delivered within seconds, so
 * "sent, has a provider id, but no delivered_at/delivery_failed_at after a grace
 * period" is the signal that a message never reached the customer.
 *
 * Scoped to providers that actually emit delivery webhooks
 * (config('glass.delivery_check.providers')); otherwise every send would look
 * unconfirmed. Each row is alerted once — the command stamps
 * delivery_unconfirmed_alerted_at on a successful send.
 *
 * Silent outside App\Support\AlertWindow (7am-7pm local): the sweep runs every
 * 5 minutes, and nobody resends a dropped text at 1am. Safe to gate precisely
 * because the stamp is only written on a successful send, so an evening drop
 * is still named at 7am (well inside the 12h lookback).
 */
class MessageDeliveryUnconfirmedAlert
{
    /** Sent-but-never-confirmed messages inside the grace/lookback window, not yet alerted. */
    public function dueMessages(): Collection
    {
        if (! AlertWindow::open()) {
            return collect();
        }

        $graceMinutes = (int) config('glass.delivery_check.grace_minutes', 15);
        $lookbackHours = (int) config('glass.delivery_check.lookback_hours', 12);

        return ScheduledMessage::query()
            ->where('status', 'sent')
            ->whereNotNull('provider_message_id')
            ->whereNull('delivered_at')
            ->whereNull('delivery_failed_at')
            ->whereNull('delivery_unconfirmed_alerted_at')
            ->where('sent_at', '<=', now()->subMinutes($graceMinutes))
            ->where('sent_at', '>=', now()->subHours($lookbackHours))
            ->where(fn ($q) => $q->whereIn('provider', $this->receiptProviders())
                ->when($this->defaultProviderEmitsReceipts(), fn ($q) => $q->orWhereNull('provider')))
            ->with(['customer', 'job'])
            ->orderBy('sent_at')
            ->get();
    }

    public function render(Collection $messages): string
    {
        $today = Timezone::now()->format('D, M j');

        $lines = [
            '🚫 <b>Service Ops — SMS not confirmed delivered</b> ('.$today.')',
            '',
            'Sent but never confirmed delivered ('.$messages->count().') — likely silently dropped by the carrier. '
                .'Worth a manual resend through Quo or a call:',
        ];

        foreach ($messages as $message) {
            $name = e($message->customer?->full_name ?? $message->job?->customer_name_snapshot ?? 'Unknown');
            $phone = e($message->to_phone_e164);
            $template = e($message->template_key?->label() ?? (string) $message->template_key);
            $sent = Timezone::toDisplay($message->sent_at)?->format('g:ia');

            $line = "• {$name} ({$phone}) — {$template}".($sent ? " · sent {$sent}" : '');
            if ($message->job) {
                $url = JobResource::getUrl('view', ['record' => $message->job]);
                $line .= " · <a href=\"{$url}\">job</a>";
            }
            $lines[] = $line;
        }

        return trim(implode("\n", $lines));
    }

    /** @return array<int, string> */
    private function receiptProviders(): array
    {
        return (array) config('glass.delivery_check.providers', ['openphone']);
    }

    /** A null per-row provider means the global default — does THAT emit receipts? */
    private function defaultProviderEmitsReceipts(): bool
    {
        $default = Setting::get('outgoing_provider') ?: config('glass.message_provider', 'log');

        return in_array($default, $this->receiptProviders(), true);
    }
}
