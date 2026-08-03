<?php

namespace App\Services\Mail;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends plain-text (or HTML) email via Resend's HTTP API.
 *
 * Credentials live in the settings table (Admin → Integrations → Email / Resend),
 * not .env — same pattern as Telegram / Stripe / Quo. Used for calibration
 * partner alerts and any other low-volume transactional mail.
 */
class ResendMailer
{
    public function apiKey(): ?string
    {
        $key = Setting::get('resend_api_key');

        return filled($key) ? trim((string) $key) : null;
    }

    public function fromAddress(): ?string
    {
        $from = Setting::get('mail_from_address');

        return filled($from) ? trim((string) $from) : null;
    }

    public function fromName(): string
    {
        $name = Setting::get('mail_from_name');

        return filled($name) ? trim((string) $name) : 'Service Ops';
    }

    public function configured(): bool
    {
        return $this->apiKey() !== null && $this->fromAddress() !== null;
    }

    /**
     * @param  string|array<int, string>  $to
     * @return array{ok: bool, id: ?string, error: ?string}
     */
    public function send(string|array $to, string $subject, string $text, ?string $html = null): array
    {
        $apiKey = $this->apiKey();
        $fromAddress = $this->fromAddress();

        if ($apiKey === null || $fromAddress === null) {
            return ['ok' => false, 'id' => null, 'error' => 'Resend not configured (API key + from address required).'];
        }

        $toList = array_values(array_filter(array_map('trim', (array) $to)));
        if ($toList === []) {
            return ['ok' => false, 'id' => null, 'error' => 'No recipients.'];
        }

        $from = $this->fromName() !== ''
            ? $this->fromName().' <'.$fromAddress.'>'
            : $fromAddress;

        $payload = [
            'from' => $from,
            'to' => $toList,
            'subject' => $subject,
            'text' => $text,
        ];
        if (filled($html)) {
            $payload['html'] = $html;
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->post('https://api.resend.com/emails', $payload);
        } catch (\Throwable $e) {
            Log::error('ResendMailer: request threw.', ['error' => $e->getMessage()]);

            return ['ok' => false, 'id' => null, 'error' => $e->getMessage()];
        }

        $json = $response->json() ?? [];

        if ($response->successful() && filled($json['id'] ?? null)) {
            return ['ok' => true, 'id' => (string) $json['id'], 'error' => null];
        }

        $error = $json['message'] ?? $json['error']['message'] ?? ('HTTP '.$response->status());
        Log::warning('ResendMailer: send failed.', [
            'status' => $response->status(),
            'error' => $error,
        ]);

        return ['ok' => false, 'id' => null, 'error' => (string) $error];
    }
}
