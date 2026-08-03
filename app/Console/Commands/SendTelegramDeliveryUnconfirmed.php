<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Telegram\MessageDeliveryUnconfirmedAlert;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Alerts on SMS that OpenPhone/Quo accepted but never confirmed delivered
 * (no message.delivered AND no message.failed webhook after a grace period) —
 * the silent-drop failure mode a long UCS-2 text hits. Scheduled every 5 min.
 * Self-gates on telegram_alerts_enabled; stamps each row so it alerts once.
 */
class SendTelegramDeliveryUnconfirmed extends Command
{
    protected $signature = 'alerts:sms-delivery-unconfirmed';

    protected $description = 'Alert on texts sent but never confirmed delivered (probable silent carrier drop).';

    public function handle(MessageDeliveryUnconfirmedAlert $alert, TelegramNotifier $telegram): int
    {
        $messages = $alert->dueMessages();

        if ($messages->isEmpty()) {
            $this->info('No unconfirmed-delivery messages.');

            return self::SUCCESS;
        }

        if (Setting::get('telegram_alerts_enabled', '1') === '0' || ! $telegram->configured()) {
            // Stamp anyway so we don't accumulate a backlog that all fires at
            // once the moment alerts are re-enabled; the ledger/job view still
            // shows the unconfirmed state.
            DB::table('scheduled_messages')->whereIn('id', $messages->pluck('id'))
                ->update(['delivery_unconfirmed_alerted_at' => now()]);
            $this->warn('Telegram not available — stamped '.$messages->count().' row(s) without alerting.');

            return self::SUCCESS;
        }

        $result = $telegram->send($alert->render($messages));

        if (! $result->success) {
            $this->error("Telegram send failed: {$result->errorCode} {$result->errorMessage}");

            return self::FAILURE; // Leave unstamped so the next run retries.
        }

        DB::table('scheduled_messages')->whereIn('id', $messages->pluck('id'))
            ->update(['delivery_unconfirmed_alerted_at' => now()]);
        $this->info('Alerted on '.$messages->count().' unconfirmed-delivery message(s).');

        return self::SUCCESS;
    }
}
