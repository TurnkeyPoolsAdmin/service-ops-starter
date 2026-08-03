<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Telegram\ReviewCallReminderAlert;
use App\Services\Telegram\TelegramNotifier;
use App\Support\AlertWindow;
use Illuminate\Console\Command;

/**
 * Posts the "call this customer for a review" nag to the Telegram group chat.
 * Scheduled hourly (8am-7pm local by default) so a first call never
 * gets forgotten — ReviewCallReminderAlert reposts the live list each run (no
 * per-row stamp). Self-gates on telegram_alerts_enabled; stays quiet when
 * nothing is outstanding or outside its configured time window.
 */
class SendTelegramReviewCalls extends Command
{
    protected $signature = 'alerts:telegram-review-calls';

    protected $description = 'Post the "call this customer for a review" nag to the Telegram group chat.';

    public function handle(ReviewCallReminderAlert $alert, TelegramNotifier $telegram): int
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

        $message = $alert->build();

        if ($message === null) {
            $this->info('No review calls outstanding (or outside the alert window) — no alert sent.');

            return self::SUCCESS;
        }

        $result = $telegram->send($message);

        if ($result->success) {
            $this->info('Posted review-call reminder alert to Telegram.');

            return self::SUCCESS;
        }

        $this->error("Telegram send failed: {$result->errorCode} {$result->errorMessage}");

        return self::FAILURE;
    }
}
