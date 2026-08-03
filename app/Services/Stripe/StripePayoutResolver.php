<?php

namespace App\Services\Stripe;

use App\Models\JobPayment;
use App\Models\StripePayout;
use App\Models\StripePayoutLine;
use Carbon\Carbon;

/**
 * Two-phase Stripe payout handling — see StripePayout's migration doc
 * comment. handlePayoutPaid() is the webhook entry point (upserts the payout
 * row, then attempts resolution inline); attemptResolution() is also called
 * standalone by the scheduled retry command (stripe:resolve-payouts, not yet
 * built) for a payout still stuck in pending_resolution/resolution_failed.
 *
 * Neither method throws — a failed Stripe call is recorded on the row
 * (resolution_status/resolution_attempts/resolution_error) rather than
 * propagated, since the webhook controller must always return 2xx once the
 * signature is valid (matches the existing checkout.session.completed
 * handler's convention).
 */
class StripePayoutResolver
{
    public function __construct(private readonly StripeClient $stripe) {}

    /** Upsert the payout row from a payout.paid webhook payload, then attempt to resolve it. */
    public function handlePayoutPaid(array $payoutObject): StripePayout
    {
        $payout = StripePayout::query()->updateOrCreate(
            ['stripe_payout_id' => (string) ($payoutObject['id'] ?? '')],
            [
                'amount_cents' => (int) ($payoutObject['amount'] ?? 0),
                'currency' => $payoutObject['currency'] ?? 'usd',
                'arrival_date' => isset($payoutObject['arrival_date'])
                    ? Carbon::createFromTimestamp((int) $payoutObject['arrival_date'])->utc()->toDateString()
                    : now()->utc()->toDateString(),
                'status' => $payoutObject['status'] ?? 'paid',
            ],
        );

        $this->attemptResolution($payout);

        return $payout;
    }

    /**
     * Fetch (or re-fetch, on retry) a payout's balance transactions and map
     * each charge back to a job_payments row via its payment_intent. A
     * no-op once the payout is already resolved or partial — those are
     * terminal successes; only pending_resolution/resolution_failed rows do
     * real work here.
     *
     * ⚠️ Assumes a Payment Link card charge reports balance_transaction.type
     * === 'charge'. Unverified against a real Stripe payout (no live
     * credentials / test fixtures available while building this) — worth
     * confirming against the first real resolved payout in production
     * before relying on the partial/resolved distinction.
     */
    public function attemptResolution(StripePayout $payout): void
    {
        if ($payout->isResolved() || $payout->isPartial()) {
            return;
        }

        try {
            $transactions = $this->stripe->listPayoutBalanceTransactions($payout->stripe_payout_id);
        } catch (\Throwable $e) {
            $payout->update([
                'resolution_status' => StripePayout::STATUS_RESOLUTION_FAILED,
                'resolution_attempts' => $payout->resolution_attempts + 1,
                'resolution_error' => $e->getMessage(),
            ]);

            return;
        }

        $hasUnresolvedCharge = false;

        foreach ($transactions as $txn) {
            $type = (string) ($txn['type'] ?? 'unknown');
            $source = $txn['source'] ?? null;
            // source is the expanded charge object when found (we ask for
            // expand[]=data.source.payment_intent); fall back to treating it
            // as a bare charge id string if Stripe ever returns it unexpanded.
            $charge = is_array($source) ? $source : null;
            $chargeId = $charge['id'] ?? (is_string($source) ? $source : null);
            $paymentIntent = $charge['payment_intent'] ?? null;
            $paymentIntentId = is_array($paymentIntent) ? ($paymentIntent['id'] ?? null) : $paymentIntent;

            $jobPayment = $paymentIntentId !== null
                ? JobPayment::query()->where('reference', $paymentIntentId)->first()
                : null;

            if ($type === 'charge' && $jobPayment === null) {
                $hasUnresolvedCharge = true;
            }

            StripePayoutLine::query()->updateOrCreate(
                [
                    'stripe_payout_id' => $payout->id,
                    'stripe_balance_transaction_id' => (string) ($txn['id'] ?? ''),
                ],
                [
                    'stripe_charge_id' => $chargeId,
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'job_payment_id' => $jobPayment?->id,
                    'type' => $type,
                    'gross_cents' => (int) ($txn['amount'] ?? 0),
                    'fee_cents' => (int) ($txn['fee'] ?? 0),
                    'net_cents' => (int) ($txn['net'] ?? 0),
                ],
            );
        }

        $payout->update([
            'resolution_status' => $hasUnresolvedCharge ? StripePayout::STATUS_PARTIAL : StripePayout::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolution_error' => null,
        ]);
    }
}
