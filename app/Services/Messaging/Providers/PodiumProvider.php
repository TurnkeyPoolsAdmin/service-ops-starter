<?php

namespace App\Services\Messaging\Providers;

use App\Services\Podium\PodiumOAuth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Production SMS provider for Podium. Sends through the location's own Podium
 * number, so $from (our Quo number) is ignored — the message lands in the same
 * thread the sales team uses. Our own send echoes back as a `message.sent`
 * webhook, which enriches the captured transcript.
 *
 *   POST {base}/v4/messages
 *   Header: Authorization: Bearer <access token>
 *   Body:   { "channel": { "identifier": <E.164>, "type": "phone" },
 *             "body": <text>, "locationUid": <uuid> }
 *
 * Reference: https://docs.podium.com/reference/messagesend-1
 */
class PodiumProvider implements MessageProvider
{
    public function __construct(
        protected PodiumOAuth $oauth,
        protected ?string $locationUid = null,
        protected ?string $apiBase = 'https://api.podium.com',
        protected ?string $version = null,
    ) {}

    public function send(string $to, string $from, string $body): ProviderResult
    {
        if (blank($this->locationUid)) {
            return ProviderResult::failed('config', 'Podium location UID is not configured.');
        }

        $token = $this->oauth->accessToken();
        if (blank($token)) {
            return ProviderResult::failed('config', 'Could not obtain a Podium access token.');
        }

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ];
        if (filled($this->version)) {
            $headers['podium-version'] = $this->version;
        }

        $url = rtrim($this->apiBase ?: 'https://api.podium.com', '/').'/v4/messages';

        try {
            $response = Http::withHeaders($headers)->post($url, [
                'channel' => ['identifier' => $to, 'type' => 'phone'],
                'body' => $body,
                'locationUid' => $this->locationUid,
            ]);
        } catch (\Throwable $e) {
            Log::error('PodiumProvider transport error', ['error' => $e->getMessage()]);

            return ProviderResult::failed('transport', $e->getMessage());
        }

        $json = $response->json() ?? [];

        if ($response->successful()) {
            $data = $json['data'] ?? $json;

            return ProviderResult::ok($data['uid'] ?? $data['id'] ?? null, $json);
        }

        return ProviderResult::failed(
            (string) $response->status(),
            $json['message'] ?? $json['error'] ?? 'Podium send failed',
            $json,
        );
    }
}
