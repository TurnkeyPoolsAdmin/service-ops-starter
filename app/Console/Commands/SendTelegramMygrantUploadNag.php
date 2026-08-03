<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Telegram\MygrantUploadNagAlert;
use App\Services\Telegram\TelegramNotifier;
use App\Support\AlertWindow;
use Illuminate\Console\Command;

/**
 * Posts the Monday-morning "upload the Mygrant order history" nag to the
 * Telegram group chat when no recent upload exists. Self-gates on
 * telegram_alerts_enabled; stays quiet when a recent upload is already on file.
 */
class SendTelegramMygrantUploadNag extends Command
{
    protected $signature = 'alerts:telegram-mygrant-upload-nag';

    protected $description = 'Post the Mygrant order-history upload nag to the Telegram group chat.';

    public function handle(MygrantUploadNagAlert $alert, TelegramNotifier $telegram): int
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
            $this->info('Recent Mygrant order-history upload on file — no nag sent.');

            return self::SUCCESS;
        }

        $result = $telegram->send($message);

        if ($result->success) {
            $this->info('Posted Mygrant upload nag to Telegram.');

            return self::SUCCESS;
        }

        $this->error("Telegram send failed: {$result->errorCode} {$result->errorMessage}");

        return self::FAILURE;
    }
}
