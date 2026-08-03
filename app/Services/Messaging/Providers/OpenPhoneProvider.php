<?php

namespace App\Services\Messaging\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Production SMS provider for OpenPhone / Quo (same provider; api.openphone.com
 * now redirects to api.quo.com — base URL is configurable in settings).
 *
 * Verified against the live docs (June 2026):
 *   POST {base}/v1/messages
 *   Header: Authorization: <raw API key>   (NOT "Bearer ...")
 *   Body:   { "content": string, "from": E.164|phoneNumberId, "to": [E.164] }
 *   Success: HTTP 202, body { "data": { "id", "status", ... } }
 *
 * Reference: https://www.quo.com/docs/api-reference/messages/send-a-text-message
 */
class OpenPhoneProvider implements MessageProvider
{
    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $baseUrl = 'https://api.openphone.com',
    ) {}

    public function send(string $to, string $from, string $body): ProviderResult
    {
        if (blank($this->apiKey)) {
            return ProviderResult::failed('config', 'OpenPhone API key is not configured.');
        }

        $url = rtrim($this->baseUrl ?: 'https://api.openphone.com', '/').'/v1/messages';

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,           // raw key, no Bearer prefix
                'Content-Type' => 'application/json',
            ])->post($url, [
                'content' => $body,
                'from' => $from,
                'to' => [$to],
            ]);
        } catch (\Throwable $e) {
            Log::error('OpenPhoneProvider transport error', ['error' => $e->getMessage()]);

            return ProviderResult::failed('transport', $e->getMessage());
        }

        $json = $response->json() ?? [];

        // Docs: success is 202 (also accept 200/201 defensively).
        if ($response->successful()) {
            $data = $json['data'] ?? [];

            return ProviderResult::ok($data['id'] ?? null, $json);
        }

        return ProviderResult::failed(
            (string) $response->status(),
            $json['message'] ?? $json['error'] ?? 'OpenPhone send failed',
            $json,
        );
    }
}
