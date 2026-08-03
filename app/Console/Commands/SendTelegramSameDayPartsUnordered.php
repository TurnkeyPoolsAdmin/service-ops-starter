<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Telegram\SameDayPartsUnorderedAlert;
use App\Services\Telegram\TelegramNotifier;
use App\Support\AlertWindow;
use Illuminate\Console\Command;

/**
 * Posts the "same-day job, part not ordered" nag to the Telegram group chat.
 * Scheduled every 30 minutes so it keeps surfacing until the part is marked
 * ordered (SameDayPartsUnorderedAlert reposts the live list each run — no
 * per-row stamp). Self-gates on telegram_alerts_enabled; stays quiet when
 * nothing is outstanding.
 */
class SendTelegramSameDayPartsUnordered extends Command
{
    protected $signature = 'alerts:telegram-same-day-parts-unordered';

    protected $description = 'Post the "same-day job, part not ordered" nag to the Telegram group chat.';

    public function handle(SameDayPartsUnorderedAlert $alert, TelegramNotifier $telegram): int
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
            $this->info('No same-day unordered parts — no alert sent.');

            return self::SUCCESS;
        }

        $result = $telegram->send($message);

        if ($result->success) {
            $this->info('Posted same-day parts-unordered alert to Telegram.');

            return self::SUCCESS;
        }

        $this->error("Telegram send failed: {$result->errorCode} {$result->errorMessage}");

        return self::FAILURE;
    }
}
