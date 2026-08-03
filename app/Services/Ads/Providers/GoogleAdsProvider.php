<?php

namespace App\Services\Ads\Providers;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Production insights provider for the Google Ads API (REST transport).
 *
 * We deliberately use the REST/HTTP interface instead of the official
 * google-ads-php SDK so there is no dependency on the `grpc` PHP extension
 * (unavailable on Cloudways shared hosting). This mirrors the HTTP-based
 * OpenPhoneProvider.
 *
 * Flow:
 *   1. Exchange the long-lived OAuth refresh token for a short-lived access
 *      token (POST https://oauth2.googleapis.com/token).
 *   2. POST a GAQL query to
 *      {base}/{version}/customers/{customerId}/googleAds:searchStream
 *      with headers: Authorization: Bearer <token>, developer-token: <dev>,
 *      login-customer-id: <mcc> (only when an MCC manages the account).
 *
 * REST quirks handled below: field names are camelCase, and integer metrics
 * (costMicros, clicks, impressions) arrive as strings.
 */
class GoogleAdsProvider implements AdsInsightsProvider
{
    protected ?string $accessToken = null;

    public function __construct(
        protected ?string $developerToken,
        protected ?string $clientId,
        protected ?string $clientSecret,
        protected ?string $refreshToken,
        protected ?string $loginCustomerId = null,   // MCC id (digits only), optional
        protected string $apiBase = 'https://googleads.googleapis.com',
        protected string $apiVersion = 'v21',
    ) {}

    public function fetchDailyMetrics(string $customerId, CarbonInterface $start, CarbonInterface $end): array
    {
        $customerId = $this->digits($customerId);

        if ($customerId === '' || blank($this->developerToken) || blank($this->refreshToken)) {
            Log::warning('GoogleAdsProvider not fully configured; skipping pull.', [
                'customer_id' => $customerId,
            ]);

            return [];
        }

        $token = $this->accessToken();
        if ($token === null) {
            return [];
        }

        $gaql = sprintf(
            'SELECT segments.date, metrics.cost_micros, metrics.conversions, '
            .'metrics.clicks, metrics.impressions FROM customer '
            ."WHERE segments.date BETWEEN '%s' AND '%s'",
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
        );

        $url = sprintf(
            '%s/%s/customers/%s/googleAds:searchStream',
            rtrim($this->apiBase, '/'),
            $this->apiVersion,
            $customerId,
        );

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'developer-token' => $this->developerToken,
            'Content-Type' => 'application/json',
        ];
        if (filled($this->loginCustomerId)) {
            $headers['login-customer-id'] = $this->digits($this->loginCustomerId);
        }

        try {
            $response = Http::withHeaders($headers)->post($url, ['query' => $gaql]);
        } catch (\Throwable $e) {
            Log::error('GoogleAdsProvider transport error', [
                'customer_id' => $customerId, 'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            Log::error('GoogleAdsProvider query failed', [
                'customer_id' => $customerId,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return [];
        }

        // searchStream returns an array of chunks, each with a "results" array.
        $chunks = $response->json() ?? [];
        $metrics = [];

        foreach ($chunks as $chunk) {
            foreach ($chunk['results'] ?? [] as $row) {
                $date = $row['segments']['date'] ?? null;
                if ($date === null) {
                    continue;
                }

                $m = $row['metrics'] ?? [];
                $metrics[] = new DailyAdMetric(
                    date: $date,
                    costMicros: (int) ($m['costMicros'] ?? 0),
                    conversions: (float) ($m['conversions'] ?? 0),
                    clicks: (int) ($m['clicks'] ?? 0),
                    impressions: (int) ($m['impressions'] ?? 0),
                );
            }
        }

        return $metrics;
    }

    /** Exchange the refresh token for an access token; null on failure. */
    protected function accessToken(): ?string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        try {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $this->refreshToken,
                'grant_type' => 'refresh_token',
            ]);
        } catch (\Throwable $e) {
            Log::error('GoogleAdsProvider OAuth transport error', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('GoogleAdsProvider OAuth token exchange failed', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return null;
        }

        return $this->accessToken = $response->json('access_token');
    }

    protected function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }
}
