<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Telegram\OpsDigest;
use App\Services\Telegram\TelegramNotifier;
use App\Support\AlertWindow;
use Illuminate\Console\Command;

/**
 * Posts the ops digest to the Telegram group chat, three times a day: 7:00am
 * (--when=morning), 12:00pm (--when=midday) and 5:00pm (--when=evening) PT.
 * Replaced alerts:telegram-daily + alerts:telegram-prepay-parts +
 * alerts:telegram-unpaid-jobs, which posted the same information on five
 * overlapping schedules — see App\Services\Telegram\OpsDigest.
 *
 * Self-gates on the telegram_alerts_enabled setting and the bot configuration
 * like every other alert. Stays quiet when nothing is pending.
 */
class SendTelegramDigest extends Command
{
    protected $signature = 'alerts:telegram-digest {--when=morning : morning (7am), midday (12pm) or evening (5pm)}';

    protected $description = 'Post the 7am / 12pm / 5pm ops digest to the Telegram group chat.';

    public function handle(OpsDigest $digest, TelegramNotifier $telegram): int
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

        $slot = (string) $this->option('when');

        if (! array_key_exists($slot, OpsDigest::SLOTS)) {
            $this->error('Unknown --when value "'.$slot.'" (expected: '.implode(', ', array_keys(OpsDigest::SLOTS)).').');

            return self::FAILURE;
        }

        $messages = $digest->buildMessages($slot);

        if ($messages === []) {
            $this->info('Nothing pending — no alert sent.');

            return self::SUCCESS;
        }

        // The morning URGENT list posts as its own message ahead of the digest —
        // one send per message, and a failure on either is fatal.
        $failed = false;

        foreach ($messages as $message) {
            $result = $telegram->send($message);

            if (! $result->success) {
                $failed = true;
                $this->error("Telegram send failed: {$result->errorCode} {$result->errorMessage}");
            }
        }

        if ($failed) {
            return self::FAILURE;
        }

        $this->info('Posted the '.$slot.' digest to Telegram ('.count($messages).' message(s)).');

        return self::SUCCESS;
    }
}
