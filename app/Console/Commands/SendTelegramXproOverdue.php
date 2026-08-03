<?php

namespace App\Console\Commands;

use App\Models\Job;
use App\Models\Setting;
use App\Services\Telegram\TelegramNotifier;
use App\Services\Telegram\XproOverdueAlert;
use App\Support\AlertWindow;
use Illuminate\Console\Command;

/**
 * Posts the Owed-from-Xpro OVERDUE escalation (money past its collection
 * deadline — noon the Wednesday after the Friday it was first owed) to the
 * Telegram group chat. Runs WEEKLY, Wednesday at noon: the deadline instant
 * itself, so the digest posts exactly as the money goes late. issues:sweep puts
 * the same jobs on the super-admin Issues queue hourly. Self-gates on the
 * telegram_alerts_enabled setting. Stamps jobs.xpro_overdue_alerted_at on a
 * successful send as a "last chased" record — the alert no longer filters on it
 * (see XproOverdueAlert), so the list reposts weekly until the money is back.
 * Stays quiet when nothing is overdue.
 */
class SendTelegramXproOverdue extends Command
{
    protected $signature = 'alerts:telegram-xpro-overdue';

    protected $description = 'Post the Owed-from-Xpro overdue escalation to the Telegram group chat.';

    public function handle(XproOverdueAlert $alert, TelegramNotifier $telegram): int
    {
        if (Setting::get('telegram_alerts_enabled', '1') === '0') {
            $this->info('Telegram alerts are disabled.');

            return self::SUCCESS;
        }

        if (! $telegram->configured()) {
            $this->warn('Telegram bot is not configured (missing token or chat id) — skipping.');

            return self::SUCCESS;
        }

        if (AlertWindow::silentToday()) {
            $this->info('Telegram alerts are off today — nothing posted.');

            return self::SUCCESS;
        }

        $jobs = $alert->dueJobs();

        if ($jobs->isEmpty()) {
            $this->info('Nothing overdue from Xpro — no alert sent.');

            return self::SUCCESS;
        }

        $result = $telegram->send($alert->render($jobs));

        if ($result->success) {
            Job::whereIn('id', $jobs->pluck('id'))->update(['xpro_overdue_alerted_at' => now()]);
            $this->info('Posted Owed-from-Xpro overdue alert to Telegram ('.$jobs->count().' job(s)).');

            return self::SUCCESS;
        }

        $this->error("Telegram send failed: {$result->errorCode} {$result->errorMessage}");

        return self::FAILURE;
    }
}
