<?php

namespace App\Services\Stripe;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Stripe REST API (https://docs.stripe.com/api).
 *
 * REST over the official SDK on purpose — same reasoning as the Plaid / Google
 * Ads integrations: no extra Composer deps to wrangle on Cloudways. The single
 * secret key lives encrypted in `settings` (Admin → Integrations). Live vs test
 * is decided entirely by the key prefix (sk_live_ / sk_test_), so there is no
 * separate environment switch to keep in sync.
 *
 * Auth model: every request is form-encoded (Stripe does not accept JSON) with a
 * Bearer secret key. We create a one-time Stripe Payment Link (not an Invoice) so
 * there is no Invoicing fee (+0.4%). The link's hosted page is what we text; card
 * data never touches this app. Invoice creation on the Payment Link is left off
 * (Stripe default) — turning it on would re-introduce the invoice fee.
 */
class StripeClient
{
    private const BASE = 'https://api.stripe.com';

    public function configured(): bool
    {
        return filled(Setting::get('stripe_secret_key'));
    }

    /** True once the webhook signing secret (whsec_…) is set. */
    public function webhookConfigured(): bool
    {
        return filled(Setting::get('stripe_webhook_secret'));
    }

    /**
     * Verify a Stripe webhook against the stored signing secret using Stripe's
     * scheme: the `Stripe-Signature` header carries `t=<ts>,v1=<hmac>[,v1=…]`,
     * where each v1 is HMAC-SHA256 of "<ts>.<raw-body>" keyed by whsec_…. We
     * accept if any v1 matches. Optionally rejects signatures older than
     * $toleranceSeconds (replay protection); pass null to skip the age check.
     */
    public function verifyWebhook(string $payload, ?string $sigHeader, ?int $toleranceSeconds = 300): bool
    {
        $secret = (string) Setting::get('stripe_webhook_secret');
        if ($secret === '' || ! filled($sigHeader)) {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $sigHeader) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, null);
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== null) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit((string) $timestamp) || $signatures === []) {
            return false;
        }

        if ($toleranceSeconds !== null && abs(now()->timestamp - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** 'live' | 'test' | null — inferred from the secret-key prefix. */
    public function mode(): ?string
    {
        $key = (string) Setting::get('stripe_secret_key');

        return match (true) {
            str_starts_with($key, 'sk_live_') => 'live',
            str_starts_with($key, 'sk_test_') => 'test',
            default => $key === '' ? null : 'test',
        };
    }

    /**
     * Create a Stripe Payment Link for one line item and return its URL.
     * Uses inline price_data so each job can have a different amount without
     * pre-creating Products/Prices. Returns null (logged) if the call fails.
     *
     * @return array{payment_link_id:string, url:string, status:string}|null
     */
    public function createPaymentLink(
        int $amountCents,
        string $description,
        array $metadata = [],
    ): ?array {
        if (! $this->configured()) {
            Log::warning('Stripe called without a secret key configured.');

            return null;
        }

        $payload = [
            'line_items[0][price_data][currency]' => 'usd',
            'line_items[0][price_data][unit_amount]' => $amountCents,
            'line_items[0][price_data][product_data][name]' => $description,
            'line_items[0][quantity]' => 1,
        ];
        foreach ($metadata as $k => $v) {
            $payload["metadata[{$k}]"] = $v;
        }

        $link = $this->post('/v1/payment_links', $payload);
        if (! isset($link['id'], $link['url'])) {
            return null;
        }

        return [
            'payment_link_id' => $link['id'],
            'url' => $link['url'],
            // Payment Links are active/inactive; we store "open" to match the
            // existing job field semantics used by the UI (unpaid = open link).
            'status' => ($link['active'] ?? true) ? 'open' : 'inactive',
        ];
    }

    /**
     * Every balance transaction Stripe bundled into one payout — charges,
     * refunds, adjustments alike (not filtered here; the caller decides what
     * to do with each type). Expands the underlying charge's payment_intent
     * so a `type=charge` line can be matched straight back to a
     * job_payments.reference without a second round-trip per line. Throws on
     * any transport or API error — unlike post()'s null-on-failure, the
     * caller (StripePayoutResolver) needs to distinguish "no lines" from
     * "the call failed" to drive its own retry state machine.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    public function listPayoutBalanceTransactions(string $payoutId): array
    {
        return $this->paginateAll('/v1/balance_transactions', [
            'payout' => $payoutId,
            'expand[]' => 'data.source.payment_intent',
        ]);
    }

    /**
     * Every payout Stripe has ever issued to this account — used only by the
     * one-time/rerunnable stripe:backfill-payouts command to catch payouts
     * that predate the payout.paid webhook being configured. Throws on any
     * transport or API error, same reasoning as listPayoutBalanceTransactions.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    public function listAllPayouts(): array
    {
        return $this->paginateAll('/v1/payouts');
    }

    /**
     * GET $path repeatedly, following Stripe's cursor pagination
     * (starting_after, 100 items per page — Stripe's max) until has_more is
     * false. Throws on any transport/API error (see get()).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    private function paginateAll(string $path, array $query = []): array
    {
        $items = [];
        $startingAfter = null;

        do {
            $pageQuery = $query + ['limit' => 100];
            if ($startingAfter !== null) {
                $pageQuery['starting_after'] = $startingAfter;
            }

            $page = $this->get($path, $pageQuery);
            $data = $page['data'] ?? [];
            $items = array_merge($items, $data);

            $hasMore = (bool) ($page['has_more'] ?? false);
            $startingAfter = $data !== [] ? end($data)['id'] ?? null : null;
        } while ($hasMore && $startingAfter !== null);

        return $items;
    }

    /**
     * GET a Stripe endpoint with the secret key as a bearer token. Throws
     * (rather than returning null, unlike post()) on any transport or HTTP
     * error, since the one caller (listPayoutBalanceTransactions, in turn
     * StripePayoutResolver) needs to distinguish "Stripe said zero results"
     * from "the request failed" to drive its retry/failure state machine.
     */
    private function get(string $path, array $query = []): array
    {
        try {
            $response = Http::withToken((string) Setting::get('stripe_secret_key'))
                ->timeout(30)
                ->get(self::BASE.$path, $query);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Stripe GET {$path} failed: ".$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'Stripe GET %s returned %d: %s',
                $path,
                $response->status(),
                $response->json('error.message') ?? 'unknown error',
            ));
        }

        return $response->json() ?? [];
    }

    /**
     * POST a form-encoded Stripe endpoint with the secret key as a bearer token.
     * Returns the decoded body on success, or null on transport/HTTP error
     * (logged). Stripe error bodies carry error.type / error.message.
     */
    private function post(string $path, array $payload): ?array
    {
        try {
            $response = Http::asForm()
                ->withToken((string) Setting::get('stripe_secret_key'))
                ->timeout(30)
                ->post(self::BASE.$path, $payload);
        } catch (\Throwable $e) {
            Log::error('Stripe request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('Stripe API error', [
                'path' => $path,
                'status' => $response->status(),
                'error_type' => $response->json('error.type'),
                'error_message' => $response->json('error.message'),
            ]);

            return null;
        }

        return $response->json();
    }
}
