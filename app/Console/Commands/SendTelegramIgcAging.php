<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Telegram\IgcAgingAlert;
use App\Services\Telegram\TelegramNotifier;
use App\Support\AlertWindow;
use Illuminate\Console\Command;

/**
 * Posts the IGC aging look-at digest (invoices with a non-zero balance open
 * more than the configured threshold) to the Telegram group chat. Self-gates
 * on telegram_alerts_enabled; stays quiet when nothing is aging.
 */
class SendTelegramIgcAging extends Command
{
    protected $signature = 'alerts:telegram-igc-aging';

    protected $description = 'Post the IGC invoice aging digest to the Telegram group chat.';

    public function handle(IgcAgingAlert $alert, TelegramNotifier $telegram): int
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
            $this->info('Nothing aging on IGC invoices — no alert sent.');

            return self::SUCCESS;
        }

        $result = $telegram->send($message);

        if ($result->success) {
            $this->info('Posted IGC aging digest to Telegram.');

            return self::SUCCESS;
        }

        $this->error("Telegram send failed: {$result->errorCode} {$result->errorMessage}");

        return self::FAILURE;
    }
}
