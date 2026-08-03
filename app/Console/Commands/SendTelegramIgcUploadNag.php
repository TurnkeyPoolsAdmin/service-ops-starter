<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Telegram\IgcUploadNagAlert;
use App\Services\Telegram\TelegramNotifier;
use App\Support\AlertWindow;
use Illuminate\Console\Command;

/**
 * Posts the Monday-morning "upload the IGC invoice history" nag to the
 * Telegram group chat when no recent upload exists. Self-gates on
 * telegram_alerts_enabled; stays quiet when a recent upload is already on file.
 */
class SendTelegramIgcUploadNag extends Command
{
    protected $signature = 'alerts:telegram-igc-upload-nag';

    protected $description = 'Post the IGC invoice-history upload nag to the Telegram group chat.';

    public function handle(IgcUploadNagAlert $alert, TelegramNotifier $telegram): int
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
            $this->info('Recent IGC invoice-history upload on file — no nag sent.');

            return self::SUCCESS;
        }

        $result = $telegram->send($message);

        if ($result->success) {
            $this->info('Posted IGC upload nag to Telegram.');

            return self::SUCCESS;
        }

        $this->error("Telegram send failed: {$result->errorCode} {$result->errorMessage}");

        return self::FAILURE;
    }
}
