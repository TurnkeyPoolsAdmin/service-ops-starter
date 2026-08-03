<?php

namespace App\Services\Telegram;

use App\Filament\Pages\SendWelcome;
use App\Filament\Resources\Jobs\JobResource;
use App\Models\Job;
use App\Models\Setting;
use App\Support\Money;
use App\Support\PhoneNumber;

/**
 * "New job booked" — the one alert that fires the instant a lead lands, posted
 * to the shared Telegram group chat like every other alert (same TelegramNotifier,
 * same `telegram_alerts_enabled` gate).
 *
 * SALES-INTAKE JOBS ONLY. A manually intaken lead is already in front of the
 * dispatcher who typed it, so alerting on it would be noise; a lead the sales
 * dashboard pushes in arrives with nobody looking at the screen, which is the
 * whole point of the alert.
 *
 * The link depends on what still needs doing:
 *  - auto-intake OFF -> the Send-welcome screen, because a human must choose
 *    the welcome before anything reaches the customer.
 *  - auto-intake ON  -> the job view, because the welcome already went out.
 *
 * Unlike the digest alerts this one is fired INLINE at creation (not by a cron),
 * so it is best-effort by design: the caller swallows failures.
 */
class NewJobBookedAlert
{
    public function __construct(private TelegramNotifier $telegram) {}

    /** Post the alert, or do nothing when it's gated off. */
    public function sendFor(Job $job, bool $autoIntakeEnabled): void
    {
        // A test lead is plumbing, not work — never announce it.
        if ($job->is_test) {
            return;
        }

        if (Setting::get('telegram_alerts_enabled', '1') === '0' || ! $this->telegram->configured()) {
            return;
        }

        $this->telegram->send($this->build($job, $autoIntakeEnabled));
    }

    /** The message body (public so a test can assert it without a fake HTTP round trip). */
    public function build(Job $job, bool $autoIntakeEnabled): string
    {
        $url = $autoIntakeEnabled
            ? JobResource::getUrl('view', ['record' => $job])
            : SendWelcome::getUrl(['job' => $job->id]);

        $lines = [
            '🆕 <b>New job booked</b> — '.e($job->entity?->code ?? '—'),
            '',
            '<a href="'.$url.'">'.e($job->customer_name_snapshot ?: 'Unknown').'</a>'
                .' · '.e($job->job_number),
        ];

        $phone = PhoneNumber::toDisplay($job->customer_phone_snapshot);
        if (filled($phone)) {
            $lines[] = '📞 '.e($phone);
        }

        $vehicle = $this->vehicle($job);
        if ($vehicle !== null) {
            $lines[] = '🚗 '.e($vehicle).($job->glass_position ? ' · '.e($job->glass_position) : '');
        }

        if ($job->quote_amount_cents !== null) {
            $lines[] = '💵 '.e(Money::format($job->quote_amount_cents));
        }

        if (filled($job->requested_daytime_raw)) {
            $lines[] = '🗓️ Requested '.e($job->requested_daytime_raw);
        }

        $rep = $job->bookingSalespersonName();
        if (filled($rep)) {
            $lines[] = '🧑‍💼 Booked by '.e($rep);
        }

        $lines[] = '';
        $lines[] = $autoIntakeEnabled
            ? '✅ Welcome sent automatically — open the job.'
            : '⚠️ Waiting on you: choose the welcome on the Send-welcome screen.';

        return implode("\n", $lines);
    }

    private function vehicle(Job $job): ?string
    {
        $composed = trim(implode(' ', array_filter([
            $job->vehicle_year, $job->vehicle_make, $job->vehicle_model,
        ])));

        return $composed ?: ($job->vehicle_raw ?: null);
    }
}
