<?php

namespace App\Services\Telegram;

use App\Models\StripePayout;
use App\Support\Money;

/**
 * Builds the immediate alert posted when stripe:resolve-payouts gives up on a
 * payout after exhausting its retry budget (glass.stripe.
 * payout_resolution_max_attempts) — Stripe's balance-transactions call kept
 * failing (outage, network) rather than the payout genuinely having no data.
 * Fired once per payout, from App\Console\Commands\StripeResolvePayouts,
 * never on a schedule of its own. The same payout also raises an Issue
 * (IssueType::StripePayoutResolutionExhausted) for the super-admin queue —
 * this is the "someone sees it today" half, that's the "it's tracked" half.
 */
class StripePayoutResolutionExhaustedAlert
{
    public function build(StripePayout $payout): string
    {
        $amount = Money::format((int) $payout->amount_cents);
        $payoutId = e($payout->stripe_payout_id);
        $attempts = (int) $payout->resolution_attempts;
        $error = e($payout->resolution_error ?? 'unknown error');

        $lines = [
            '🚨 <b>Stripe payout resolution failed</b>',
            '',
            "Payout: {$payoutId} ({$amount}, arrived {$payout->arrival_date})",
            "Gave up after {$attempts} attempt(s).",
            "Last error: {$error}",
            '',
            'This payout still needs to be matched manually on the Bank Transactions ledger — '
                .'the automatic Stripe-payout matching never resolved which job payments it covers.',
        ];

        return implode("\n", $lines);
    }
}
