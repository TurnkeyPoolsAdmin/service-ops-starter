<?php

namespace App\Services\Telegram;

use App\Models\Setting;
use App\Support\AlertWindow;
use App\Support\Timezone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts internal operational alerts to a Telegram group chat via the Bot API.
 *
 * The bot token (encrypted) and the destination chat id live in the settings
 * table (Admin → Integrations → Telegram); the API host is pinned in
 * config('glass.telegram.api_base'). Unlike the customer SMS providers this is
 * a one-way internal channel — no inbound handling, no per-recipient routing.
 *
 * Nothing here is env-gated: alerts go to our own group chat, so there is no
 * "don't message real customers in dev" concern. Sending is simply a no-op when
 * the bot is unconfigured, or when today is a silent day (Sunday) — see send().
 */
class TelegramNotifier
{
    public function botToken(): ?string
    {
        $token = Setting::get('telegram_bot_token');

        return filled($token) ? trim((string) $token) : null;
    }

    public function chatId(): ?string
    {
        $chatId = Setting::get('telegram_chat_id');

        return filled($chatId) ? trim((string) $chatId) : null;
    }

    /** Both the token and the destination chat id must be set to send. */
    public function configured(): bool
    {
        return $this->botToken() !== null && $this->chatId() !== null;
    }

    /**
     * Send a message to the configured group chat. Returns a small result object
     * rather than throwing, mirroring the SMS providers — transport failures are
     * logged and surfaced as a failed result so callers can react without a try.
     *
     * This is also where the SILENT DAY rule is enforced, because it is the one
     * choke point every alert goes through — the three ops digests, the weekly
     * Xpro digests, the interval nags, and the inline one-shots (a booking, a
     * failed text, a calibration heads-up) alike. Gating it per command would
     * leave the next alert somebody adds free to post on a Sunday.
     *
     * $force is for a human pressing "Send test to Telegram" on the Integrations
     * page: they are standing at the screen waiting to see the bot work, and a
     * silent Sunday would read as a broken bot.
     */
    public function send(string $text, bool $force = false): TelegramResult
    {
        if (! $force && AlertWindow::silentToday()) {
            return TelegramResult::suppressed('Telegram alerts are off on '.Timezone::now()->format('l').'.');
        }

        $token = $this->botToken();
        $chatId = $this->chatId();

        if ($token === null || $chatId === null) {
            return TelegramResult::failed('not_configured', 'Telegram bot token or chat id is not set.');
        }

        $base = rtrim((string) config('glass.telegram.api_base', 'https://api.telegram.org'), '/');
        $url = "{$base}/bot{$token}/sendMessage";

        try {
            $response = Http::asJson()->post($url, [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('TelegramNotifier: send threw.', ['error' => $e->getMessage()]);

            return TelegramResult::failed('transport', $e->getMessage());
        }

        $json = $response->json();

        if ($response->successful() && ($json['ok'] ?? false) === true) {
            return TelegramResult::ok($json['result']['message_id'] ?? null, $json);
        }

        $description = $json['description'] ?? ('HTTP '.$response->status());
        Log::warning('TelegramNotifier: send failed.', [
            'status' => $response->status(),
            'description' => $description,
        ]);

        return TelegramResult::failed((string) $response->status(), $description, $json);
    }
}
