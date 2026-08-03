<?php

namespace App\Services\Health;

use App\Models\PlaidItem;
use App\Models\Setting;
use App\Services\Plaid\PlaidClient;
use App\Services\Podium\PodiumOAuth;
use App\Services\RingCentral\RingCentralOAuth;
use Illuminate\Support\Facades\Http;

/**
 * Actively probes every external connector we depend on, so a dead credential
 * announces itself instead of being discovered days later through its symptoms.
 *
 * This exists because of a real incident: Podium's rotating OAuth refresh token
 * died on 2026-07-13 and nothing said a word for eight days — 13 welcome texts
 * failed one at a time while the Intake screen still looked healthy (its live
 * thread pull silently falls back to webhook-captured rows). The lesson is that
 * "it looks fine in the UI" is not evidence a credential still works; only
 * asking the provider is.
 *
 * Two rules shape every probe here:
 *
 *  1. UNCONFIGURED IS NOT UNHEALTHY. A connector we've never set up (or
 *     deliberately don't use, like RingCentral) is skipped entirely rather than
 *     reported down — otherwise the alert is permanently noisy and gets muted,
 *     which is exactly how the original failure stayed invisible.
 *  2. PROBES MUST BE CHEAP AND SAFE. Read-only calls, short timeouts, no sends,
 *     no writes, no cost. This runs hourly forever.
 */
class ConnectorHealthChecker
{
    private const TIMEOUT = 10;

    public function __construct(
        private PodiumOAuth $podium,
        private RingCentralOAuth $ringCentral,
        private PlaidClient $plaid,
    ) {}

    /**
     * Probe every configured connector.
     *
     * @return array<int, ConnectorStatus>
     */
    public function check(): array
    {
        $results = [];

        foreach ($this->probes() as $key => $probe) {
            $status = $this->run($key, $probe);
            if ($status !== null) {
                $results[] = $status;
            }
        }

        return $results;
    }

    /**
     * Each probe returns a ConnectorStatus, or null when the connector isn't
     * configured and should be skipped. Wrapped so a transport blow-up reads as
     * "down" rather than taking the whole sweep with it.
     */
    private function run(string $key, callable $probe): ?ConnectorStatus
    {
        try {
            return $probe();
        } catch (\Throwable $e) {
            return ConnectorStatus::down($key, $key, 'Probe threw: '.$e->getMessage());
        }
    }

    /** @return array<string, callable(): ?ConnectorStatus> */
    private function probes(): array
    {
        return [
            'podium' => fn () => $this->checkPodium(),
            'openphone' => fn () => $this->checkOpenPhone(),
            'ringcentral' => fn () => $this->checkRingCentral(),
            'google_ads' => fn () => $this->checkGoogleAds(),
            'plaid' => fn () => $this->checkPlaid(),
            'stripe' => fn () => $this->checkStripe(),
        ];
    }

    /**
     * Podium: the rotating refresh token is the fragile part — one-time-use, so
     * a lost rotation or a revoked/switched account kills it permanently. Asking
     * for an access token exercises exactly that path.
     */
    private function checkPodium(): ?ConnectorStatus
    {
        $label = 'Podium';

        if (blank(Setting::get('podium_refresh_token'))) {
            return null; // Never connected — nothing to be broken.
        }

        return $this->podium->accessToken() !== null
            ? ConnectorStatus::ok('podium', $label)
            : ConnectorStatus::down(
                'podium',
                $label,
                'The OAuth refresh token was rejected — outgoing Podium texts are failing.',
                'Admin > Integrations > Reconnect Podium (log in with the Podium account that owns the location).',
            );
    }

    /** Quo/OpenPhone: a raw API key, so a cheap authenticated GET is the probe. */
    private function checkOpenPhone(): ?ConnectorStatus
    {
        $label = 'Quo / OpenPhone';
        $apiKey = Setting::get('openphone_api_key');

        if (blank($apiKey)) {
            return null;
        }

        $base = rtrim((string) Setting::get('openphone_base_url', 'https://api.openphone.com'), '/');
        $response = Http::withHeaders(['Authorization' => $apiKey])
            ->timeout(self::TIMEOUT)
            ->get($base.'/v1/phone-numbers');

        if ($response->successful()) {
            return ConnectorStatus::ok('openphone', $label);
        }

        return ConnectorStatus::down(
            'openphone',
            $label,
            'API key rejected (HTTP '.$response->status().') — outgoing texts are failing.',
            'Admin > Integrations > re-paste the Quo API key.',
        );
    }

    /**
     * RingCentral mints tokens from a long-lived JWT, so there's no rotating
     * secret to lose — but the JWT itself can be revoked or expire.
     */
    private function checkRingCentral(): ?ConnectorStatus
    {
        $label = 'RingCentral';

        $configured = filled(Setting::get('ringcentral_client_id'))
            && filled(Setting::get('ringcentral_client_secret'))
            && filled(Setting::get('ringcentral_jwt'));

        if (! $configured) {
            return null;
        }

        return $this->ringCentral->accessToken() !== null
            ? ConnectorStatus::ok('ringcentral', $label)
            : ConnectorStatus::down(
                'ringcentral',
                $label,
                'The JWT credential was rejected.',
                'Admin > Integrations > re-issue the RingCentral JWT credential.',
            );
    }

    /**
     * Google Ads: the documented failure mode is an OAuth consent screen left in
     * "Testing", which expires the refresh token after 7 days and kills the
     * daily ROAS pull silently. Same token exchange the provider does.
     */
    private function checkGoogleAds(): ?ConnectorStatus
    {
        $label = 'Google Ads';

        $refreshToken = Setting::get('google_ads_refresh_token');
        $clientId = Setting::get('google_ads_client_id');
        $clientSecret = Setting::get('google_ads_client_secret');

        if (blank($refreshToken) || blank($clientId) || blank($clientSecret)) {
            return null;
        }

        $response = Http::asForm()->timeout(self::TIMEOUT)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->successful() && filled($response->json('access_token'))) {
            return ConnectorStatus::ok('google_ads', $label);
        }

        return ConnectorStatus::down(
            'google_ads',
            $label,
            'Refresh token rejected ('.($response->json('error') ?: 'HTTP '.$response->status()).') — ad spend has stopped importing.',
            'Re-authorize Google Ads, and set the OAuth consent screen to "In production" so the token stops expiring weekly.',
        );
    }

    /**
     * Plaid: the Item itself carries the health signal — a bank that needs
     * re-authentication reports ITEM_LOGIN_REQUIRED and the daily pull quietly
     * imports nothing.
     */
    private function checkPlaid(): ?ConnectorStatus
    {
        $label = 'Plaid (bank feed)';

        if (! $this->plaid->configured()) {
            return null;
        }

        $item = PlaidItem::query()->whereNotNull('access_token')->first();
        if (! $item) {
            return null; // Credentials present but no bank linked yet.
        }

        $response = $this->plaid->getItem($item->access_token);
        if ($response === null) {
            return ConnectorStatus::down('plaid', $label, 'Plaid did not respond to an item lookup.');
        }

        $error = data_get($response, 'item.error.error_code') ?: data_get($response, 'error_code');
        if (blank($error)) {
            return ConnectorStatus::ok('plaid', $label);
        }

        return ConnectorStatus::down(
            'plaid',
            $label,
            'Bank connection error: '.$error.' — transactions have stopped importing.',
            'Admin > Bank Transactions > Connect Chase, and re-authenticate the bank login.',
        );
    }

    /** Stripe: a read-only balance fetch is the cheapest authenticated call. */
    private function checkStripe(): ?ConnectorStatus
    {
        $label = 'Stripe';
        $key = Setting::get('stripe_secret_key');

        if (blank($key)) {
            return null;
        }

        $response = Http::withToken($key)->timeout(self::TIMEOUT)->get('https://api.stripe.com/v1/balance');

        if ($response->successful()) {
            return ConnectorStatus::ok('stripe', $label);
        }

        return ConnectorStatus::down(
            'stripe',
            $label,
            'Secret key rejected (HTTP '.$response->status().') — card payment links will fail.',
            'Admin > Integrations > re-paste the Stripe secret key.',
        );
    }
}
