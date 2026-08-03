<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Telegram\IgcReturnRateAlert;
use App\Services\Telegram\TelegramNotifier;
use App\Support\AlertWindow;
use Illuminate\Console\Command;

/**
 * Posts the IGC monthly return-rate digest (I3) to the Telegram group chat.
 * Self-gates on telegram_alerts_enabled; stays quiet when last month's rate
 * was under the configured threshold (or nothing was ordered at all).
 */
class SendTelegramIgcReturnRate extends Command
{
    protected $signature = 'alerts:telegram-igc-return-rate';

    protected $description = 'Post the IGC monthly return-rate digest to the Telegram group chat.';

    public function handle(IgcReturnRateAlert $alert, TelegramNotifier $telegram): int
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
            $this->info('IGC return rate under threshold last month — no alert sent.');

            return self::SUCCESS;
        }

        $result = $telegram->send($message);

        if ($result->success) {
            $this->info('Posted IGC return-rate digest to Telegram.');

            return self::SUCCESS;
        }

        $this->error("Telegram send failed: {$result->errorCode} {$result->errorMessage}");

        return self::FAILURE;
    }
}
