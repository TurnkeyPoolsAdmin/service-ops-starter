<?php

namespace App\Services\RingCentral;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a RingCentral API access token using the server-to-server JWT flow.
 *
 * Unlike Podium's rotating refresh token, a RingCentral JWT credential is
 * long-lived: each access token is minted fresh from the same JWT, so there is
 * no rotated secret to persist (and nothing to expire silently). Access tokens
 * last ~3600s; we cache a little under that and re-mint on a miss.
 *
 * Token endpoint: POST {server}/restapi/oauth/token  (x-www-form-urlencoded)
 *   Header: Authorization: Basic base64(clientId:clientSecret)
 *   Body:   grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer
 *           assertion=<JWT credential>
 * Reference: https://developers.ringcentral.com/guide/authentication/jwt-flow
 */
class RingCentralOAuth
{
    private const CACHE_KEY = 'ringcentral_access_token';

    /** Mint (or return cached) access token, or null if not configured / rejected. */
    public function accessToken(): ?string
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (filled($cached)) {
            return $cached;
        }

        // Serialize refreshes so concurrent workers don't all hit the token endpoint.
        $lock = Cache::lock('ringcentral_token_refresh', 30);
        if (! $lock->get()) {
            $lock->block(15);
        }

        try {
            if (filled($again = Cache::get(self::CACHE_KEY))) {
                return $again;
            }

            return $this->mint();
        } finally {
            optional($lock)->release();
        }
    }

    private function mint(): ?string
    {
        $clientId = Setting::get('ringcentral_client_id');
        $clientSecret = Setting::get('ringcentral_client_secret');
        $jwt = Setting::get('ringcentral_jwt');

        if (blank($clientId) || blank($clientSecret) || blank($jwt)) {
            Log::error('RingCentralOAuth: missing client id/secret or JWT credential');

            return null;
        }

        $url = $this->serverUrl().config('glass.ringcentral.token_path');

        try {
            $response = Http::asForm()
                ->withBasicAuth($clientId, $clientSecret)
                ->post($url, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);
        } catch (\Throwable $e) {
            Log::error('RingCentralOAuth: token request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('RingCentralOAuth: token endpoint rejected', [
                'status' => $response->status(),
                'error' => data_get($response->json(), 'error_description') ?? data_get($response->json(), 'error'),
            ]);

            return null;
        }

        $json = $response->json() ?? [];
        $accessToken = $json['access_token'] ?? null;

        if (blank($accessToken)) {
            Log::error('RingCentralOAuth: no access_token in response');

            return null;
        }

        // Honor the server's expiry when present, but stay under it; fall back to config.
        $ttl = (int) ($json['expires_in'] ?? 0);
        $ttl = $ttl > 60 ? $ttl - 60 : (int) config('glass.ringcentral.token_ttl_seconds', 3300);

        Cache::put(self::CACHE_KEY, $accessToken, now()->addSeconds($ttl));

        return $accessToken;
    }

    /** Live server URL (settings override → config default), trailing slash trimmed. */
    public function serverUrl(): string
    {
        $url = Setting::get('ringcentral_server_url')
            ?: config('glass.ringcentral.server_url', 'https://platform.ringcentral.com');

        return rtrim($url, '/');
    }
}
