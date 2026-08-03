<?php

namespace App\Services\Podium;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a Podium API access token from the stored OAuth credentials.
 *
 * Podium access tokens (JWT) expire after ~10h, so we cache for 9h and only hit
 * the token endpoint on a miss. The refresh token can rotate on each exchange —
 * if a new one comes back we persist it, otherwise the next refresh would fail.
 *
 * Token endpoint: POST {token_url} JSON
 *   { client_id, client_secret, grant_type: "refresh_token", refresh_token }
 * Reference: https://docs.podium.com/docs/oauth
 */
class PodiumOAuth
{
    private const CACHE_KEY = 'podium_access_token';

    /**
     * Build the consent URL the business owner visits to authorize this app
     * against their Podium account. They log in with their normal Podium login.
     */
    public function authorizeUrl(string $redirectUri, string $state): ?string
    {
        $clientId = Setting::get('podium_client_id');
        if (blank($clientId)) {
            return null;
        }

        return config('glass.podium.authorize_url').'?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => config('glass.podium.scopes'),
            'state' => $state,
        ]);
    }

    /**
     * Exchange the authorization code from the consent redirect for tokens, and
     * persist the refresh token. Returns true on success.
     */
    public function exchangeAuthorizationCode(string $code, string $redirectUri): bool
    {
        $clientId = Setting::get('podium_client_id');
        $clientSecret = Setting::get('podium_client_secret');

        if (blank($clientId) || blank($clientSecret)) {
            return false;
        }

        try {
            $response = Http::asJson()->post(config('glass.podium.token_url'), [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]);
        } catch (\Throwable $e) {
            Log::error('PodiumOAuth: code exchange failed', ['error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::error('PodiumOAuth: code exchange rejected', ['status' => $response->status()]);

            return false;
        }

        $json = $response->json() ?? [];
        if (blank($json['refresh_token'] ?? null)) {
            return false;
        }

        Setting::put('podium_refresh_token', $json['refresh_token'], sensitive: true);

        if (filled($json['access_token'] ?? null)) {
            Cache::put(self::CACHE_KEY, $json['access_token'], now()->addHours(9));
        }

        return true;
    }

    public function accessToken(): ?string
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (filled($cached)) {
            return $cached;
        }

        // Serialize refreshes so a rotating refresh token isn't spent twice.
        $lock = Cache::lock('podium_token_refresh', 30);
        if (! $lock->get()) {
            // Another worker is refreshing; brief wait then re-read the cache.
            $lock->block(15);
        }

        try {
            if (filled($again = Cache::get(self::CACHE_KEY))) {
                return $again;
            }

            return $this->refresh();
        } finally {
            optional($lock)->release();
        }
    }

    private function refresh(): ?string
    {
        $clientId = Setting::get('podium_client_id');
        $clientSecret = Setting::get('podium_client_secret');
        $refreshToken = Setting::get('podium_refresh_token');

        if (blank($clientId) || blank($clientSecret) || blank($refreshToken)) {
            Log::error('PodiumOAuth: missing OAuth credentials');

            return null;
        }

        try {
            $response = Http::asJson()->post(config('glass.podium.token_url'), [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);
        } catch (\Throwable $e) {
            Log::error('PodiumOAuth: token request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('PodiumOAuth: token endpoint rejected refresh', ['status' => $response->status()]);

            return null;
        }

        $json = $response->json() ?? [];
        $accessToken = $json['access_token'] ?? null;

        if (blank($accessToken)) {
            Log::error('PodiumOAuth: no access_token in response');

            return null;
        }

        // Persist a rotated refresh token so the next refresh still works.
        $rotated = $json['refresh_token'] ?? null;
        if (filled($rotated) && $rotated !== $refreshToken) {
            Setting::put('podium_refresh_token', $rotated, sensitive: true);
        }

        Cache::put(self::CACHE_KEY, $accessToken, now()->addHours(9));

        return $accessToken;
    }
}
