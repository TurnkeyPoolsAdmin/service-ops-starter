<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Telegram\TelegramNotifier;
use App\Services\Telegram\WeeklyXproAlert;
use App\Support\AlertWindow;
use Illuminate\Console\Command;

/**
 * Posts the weekly "Owed from Xpro" digest (every job the Xpro team collected
 * payment on that we still need to collect back) to the Telegram group chat.
 * Scheduled Fridays; self-gates on the `telegram_alerts_enabled` setting. Stays
 * quiet when nothing is outstanding (WeeklyXproAlert::build returns null).
 */
class SendTelegramXproWeekly extends Command
{
    protected $signature = 'alerts:telegram-xpro-weekly';

    protected $description = 'Post the weekly Owed-from-Xpro collections digest to the Telegram group chat.';

    public function handle(WeeklyXproAlert $alert, TelegramNotifier $telegram): int
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
            $this->info('Nothing owed from Xpro — no alert sent.');

            return self::SUCCESS;
        }

        $result = $telegram->send($message);

        if ($result->success) {
            $this->info('Posted weekly Owed-from-Xpro alert to Telegram.');

            return self::SUCCESS;
        }

        $this->error("Telegram send failed: {$result->errorCode} {$result->errorMessage}");

        return self::FAILURE;
    }
}
