<?php

namespace App\Services\Plaid;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Plaid REST API (https://plaid.com/docs/api/).
 *
 * REST over the official SDK on purpose — same reasoning as the Google Ads
 * integration: no extra Composer deps / grpc to wrangle on Cloudways. Credentials
 * (client_id + secret) live encrypted in `settings`; the environment switch
 * (sandbox vs production) picks the base host.
 *
 * Auth model: every Plaid request carries client_id + secret in the JSON body
 * (not a bearer header). The per-bank `access_token` (obtained once via Plaid Link)
 * is also passed in the body for data endpoints.
 */
class PlaidClient
{
    public function configured(): bool
    {
        return filled(Setting::get('plaid_client_id')) && filled(Setting::get('plaid_secret'));
    }

    public function environment(): string
    {
        return Setting::get('plaid_environment', 'sandbox');
    }

    public function baseUrl(): string
    {
        return $this->environment() === 'production'
            ? 'https://production.plaid.com'
            : 'https://sandbox.plaid.com';
    }

    /**
     * Create a short-lived link_token used to launch Plaid Link in the browser.
     * `$redirectUri` is required for OAuth banks (Chase), and must exactly match a
     * redirect URI registered in the Plaid dashboard.
     */
    public function createLinkToken(?string $redirectUri = null): ?string
    {
        $payload = [
            'client_name' => 'Service Ops',
            'language' => 'en',
            'country_codes' => ['US'],
            'user' => ['client_user_id' => 'glass-ops'],
            'products' => ['transactions'],
        ];

        if (filled($redirectUri)) {
            $payload['redirect_uri'] = $redirectUri;
        }

        return $this->post('/link/token/create', $payload)['link_token'] ?? null;
    }

    /**
     * Exchange the one-time public_token returned by Plaid Link for a durable
     * access_token + item_id. Returns ['access_token' => ..., 'item_id' => ...] or null.
     */
    public function exchangePublicToken(string $publicToken): ?array
    {
        $data = $this->post('/item/public_token/exchange', ['public_token' => $publicToken]);

        if (! isset($data['access_token'], $data['item_id'])) {
            return null;
        }

        return ['access_token' => $data['access_token'], 'item_id' => $data['item_id']];
    }

    /** Institution + account metadata for a linked Item. */
    public function getItem(string $accessToken): ?array
    {
        return $this->post('/item/get', ['access_token' => $accessToken]);
    }

    /**
     * Balances for every account under an Item, via /accounts/get. Unlike
     * /transactions/sync — whose `accounts` array is only populated alongside
     * transaction changes, so it comes back empty once the cursor is caught up —
     * this always returns all accounts. We use /accounts/get (cached balances,
     * included with the transactions product) rather than /accounts/balance/get,
     * which needs the separately-authorized `balance` product. Returns the accounts
     * array (each with a `balances` object) or null on error.
     */
    public function getBalances(string $accessToken): ?array
    {
        $data = $this->post('/accounts/get', ['access_token' => $accessToken]);

        return $data['accounts'] ?? null;
    }

    public function getInstitution(string $institutionId): ?array
    {
        return $this->post('/institutions/get_by_id', [
            'institution_id' => $institutionId,
            'country_codes' => ['US'],
        ]);
    }

    /**
     * One page of /transactions/sync. The caller loops while has_more is true,
     * feeding next_cursor back in. Response shape:
     *   ['added' => [...], 'modified' => [...], 'removed' => [...],
     *    'next_cursor' => '...', 'has_more' => bool, 'accounts' => [...]]
     */
    public function syncTransactions(string $accessToken, ?string $cursor): ?array
    {
        $payload = ['access_token' => $accessToken, 'count' => 500];

        if (filled($cursor)) {
            $payload['cursor'] = $cursor;
        }

        return $this->post('/transactions/sync', $payload);
    }

    /**
     * POST a Plaid endpoint with credentials injected. Returns the decoded body on
     * success, or null on transport/HTTP error (logged). Plaid error bodies carry
     * error_code/error_message.
     */
    private function post(string $path, array $payload): ?array
    {
        if (! $this->configured()) {
            Log::warning('Plaid called without credentials configured', ['path' => $path]);

            return null;
        }

        $body = array_merge([
            'client_id' => Setting::get('plaid_client_id'),
            'secret' => Setting::get('plaid_secret'),
        ], $payload);

        try {
            $response = Http::asJson()->timeout(30)->post($this->baseUrl().$path, $body);
        } catch (\Throwable $e) {
            Log::error('Plaid request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('Plaid API error', [
                'path' => $path,
                'status' => $response->status(),
                'error_code' => $response->json('error_code'),
                'error_message' => $response->json('error_message'),
            ]);

            return null;
        }

        return $response->json();
    }
}
