<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\WarrantyClaim;
use App\Services\Telegram\TelegramNotifier;
use App\Services\Telegram\WarrantyOverdueCompletionAlert;
use App\Support\AlertWindow;
use Illuminate\Console\Command;

/**
 * Posts the "warranty return visit overdue to be marked complete" alert to the
 * Telegram group chat (scheduled visits still not resolved a grace period past
 * their window). Runs every 15 minutes; self-gates on telegram_alerts_enabled.
 * Stamps overdue_completion_alerted_at on every successful send — that column is
 * the "last alerted" clock the alert's repeat interval reads, so an overdue visit
 * keeps pinging hourly (7am-7pm) until it is resolved. Stays quiet when nothing
 * is overdue or outside the window.
 */
class SendTelegramWarrantyOverdue extends Command
{
    protected $signature = 'alerts:telegram-warranty-overdue';

    protected $description = 'Post the "warranty visits overdue to be marked complete" alert to the Telegram group chat.';

    public function handle(WarrantyOverdueCompletionAlert $alert, TelegramNotifier $telegram): int
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

        $claims = $alert->dueClaims();

        if ($claims->isEmpty()) {
            $this->info('No overdue warranty visits — no alert sent.');

            return self::SUCCESS;
        }

        $result = $telegram->send($alert->render($claims));

        if ($result->success) {
            WarrantyClaim::whereIn('id', $claims->pluck('id'))
                ->update(['overdue_completion_alerted_at' => now()]);
            $this->info('Posted overdue-warranty alert to Telegram ('.$claims->count().' visit(s)).');

            return self::SUCCESS;
        }

        $this->error("Telegram send failed: {$result->errorCode} {$result->errorMessage}");

        return self::FAILURE;
    }
}
