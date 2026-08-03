<?php

namespace App\Console\Commands;

use App\Models\Job;
use App\Models\Setting;
use App\Services\Telegram\OverdueCompletionAlert;
use App\Services\Telegram\TelegramNotifier;
use App\Support\AlertWindow;
use Illuminate\Console\Command;

/**
 * Posts the "overdue to be marked complete" alert (scheduled jobs still not
 * work-complete a grace period past their arrival window) to the Telegram group
 * chat. Runs every 15 minutes; self-gates on the telegram_alerts_enabled
 * setting. Stamps jobs.overdue_completion_alerted_at on every successful send —
 * that column is the "last alerted" clock the alert's repeat interval reads, so
 * an overdue job keeps pinging hourly (7am-7pm) until it is marked complete.
 * Stays quiet when nothing is overdue or outside the window.
 */
class SendTelegramOverdueJobs extends Command
{
    protected $signature = 'alerts:telegram-overdue-jobs';

    protected $description = 'Post the "jobs overdue to be marked complete" alert to the Telegram group chat.';

    public function handle(OverdueCompletionAlert $alert, TelegramNotifier $telegram): int
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
            $this->info('No overdue jobs — no alert sent.');

            return self::SUCCESS;
        }

        $result = $telegram->send($alert->render($jobs));

        if ($result->success) {
            Job::whereIn('id', $jobs->pluck('id'))->update(['overdue_completion_alerted_at' => now()]);
            $this->info('Posted overdue-completion alert to Telegram ('.$jobs->count().' job(s)).');

            return self::SUCCESS;
        }

        $this->error("Telegram send failed: {$result->errorCode} {$result->errorMessage}");

        return self::FAILURE;
    }
}
