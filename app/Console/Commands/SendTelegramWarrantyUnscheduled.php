<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Telegram\TelegramNotifier;
use App\Services\Telegram\UnscheduledWarrantyAlert;
use App\Support\AlertWindow;
use Illuminate\Console\Command;

/**
 * Posts the "warranty visits still to schedule" nag to the Telegram group chat.
 * Scheduled a few times a day so a filed warranty keeps surfacing until the
 * return visit is booked. Self-gates on telegram_alerts_enabled; stays quiet
 * when nothing is unscheduled (UnscheduledWarrantyAlert::build returns null).
 */
class SendTelegramWarrantyUnscheduled extends Command
{
    protected $signature = 'alerts:telegram-warranty-unscheduled';

    protected $description = 'Post the unscheduled-warranty-visits nag to the Telegram group chat.';

    public function handle(UnscheduledWarrantyAlert $alert, TelegramNotifier $telegram): int
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
            $this->info('No unscheduled warranty visits — no alert sent.');

            return self::SUCCESS;
        }

        $result = $telegram->send($message);

        if ($result->success) {
            $this->info('Posted unscheduled-warranty alert to Telegram.');

            return self::SUCCESS;
        }

        $this->error("Telegram send failed: {$result->errorCode} {$result->errorMessage}");

        return self::FAILURE;
    }
}
