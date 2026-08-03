<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Telegram\IgcChargeResultAlert;
use App\Services\Telegram\TelegramNotifier;
use App\Support\AlertWindow;
use Illuminate\Console\Command;

/**
 * Posts the latest IGC card charge's reconciliation result — no payment
 * record at all (I1), or a memo/credit-balance mismatch (I2) — to the
 * Telegram group chat. Self-gates on telegram_alerts_enabled; stays quiet
 * once it ties out or a suggestion is waiting on a one-click confirm.
 */
class SendTelegramIgcChargeResult extends Command
{
    protected $signature = 'alerts:telegram-igc-charge-result';

    protected $description = 'Post the latest IGC charge reconciliation result to the Telegram group chat.';

    public function handle(IgcChargeResultAlert $alert, TelegramNotifier $telegram): int
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
            $this->info('Nothing to report on the latest IGC charge — no alert sent.');

            return self::SUCCESS;
        }

        $result = $telegram->send($message);

        if ($result->success) {
            $this->info('Posted IGC charge result to Telegram.');

            return self::SUCCESS;
        }

        $this->error("Telegram send failed: {$result->errorCode} {$result->errorMessage}");

        return self::FAILURE;
    }
}
