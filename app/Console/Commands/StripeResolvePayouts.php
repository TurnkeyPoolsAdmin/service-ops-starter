<?php

namespace App\Console\Commands;

use App\Enums\IssueType;
use App\Models\Issue;
use App\Models\Setting;
use App\Models\StripePayout;
use App\Services\Stripe\StripePayoutResolver;
use App\Services\Telegram\StripePayoutResolutionExhaustedAlert;
use App\Services\Telegram\TelegramNotifier;
use Illuminate\Console\Command;

/**
 * Retries StripePayoutResolver::attemptResolution() for every payout still
 * stuck in pending_resolution/resolution_failed (a Stripe outage or network
 * blip at webhook time) — the webhook itself only gets one shot per delivery,
 * this command owns the retry loop rather than relying on Stripe's own
 * (limited, uncontrolled) webhook redelivery window.
 *
 * A payout that fails again AFTER its resolution_attempts hits
 * glass.stripe.payout_resolution_max_attempts flips to
 * resolution_exhausted — it stops being retried by this command (excluded by
 * the same attempts < max_attempts filter that selects candidates) and gets
 * escalated ONCE: an Issue for the super-admin queue + an immediate Telegram
 * post. From there it's a manual-reconciliation case via the existing
 * bank-ledger Match modal, same as any payout this whole feature never
 * covers.
 */
class StripeResolvePayouts extends Command
{
    protected $signature = 'stripe:resolve-payouts';

    protected $description = 'Retry resolving Stripe payouts whose balance-transaction fetch previously failed.';

    public function handle(StripePayoutResolver $resolver, TelegramNotifier $telegram, StripePayoutResolutionExhaustedAlert $alertBuilder): int
    {
        $maxAttempts = (int) config('glass.stripe.payout_resolution_max_attempts', 10);

        $payouts = StripePayout::query()
            ->needsResolution()
            ->where('resolution_attempts', '<', $maxAttempts)
            ->get();

        if ($payouts->isEmpty()) {
            $this->info('No Stripe payouts need resolution.');

            return self::SUCCESS;
        }

        $resolved = 0;
        $stillFailing = 0;
        $exhausted = 0;

        foreach ($payouts as $payout) {
            $resolver->attemptResolution($payout);
            $payout->refresh();

            if (in_array($payout->resolution_status, [StripePayout::STATUS_RESOLVED, StripePayout::STATUS_PARTIAL], true)) {
                $resolved++;

                continue;
            }

            if ($payout->resolution_attempts >= $maxAttempts) {
                $payout->update(['resolution_status' => StripePayout::STATUS_RESOLUTION_EXHAUSTED]);
                $exhausted++;
                $this->escalate($payout, $telegram, $alertBuilder);
            } else {
                $stillFailing++;
            }
        }

        $this->info("Stripe payout resolution: {$resolved} resolved, {$stillFailing} still retrying, {$exhausted} exhausted.");

        return self::SUCCESS;
    }

    private function escalate(StripePayout $payout, TelegramNotifier $telegram, StripePayoutResolutionExhaustedAlert $alertBuilder): void
    {
        Issue::raise(IssueType::StripePayoutResolutionExhausted, $payout, [
            'stripe_payout_id' => $payout->stripe_payout_id,
            'amount_cents' => $payout->amount_cents,
            'resolution_attempts' => $payout->resolution_attempts,
            'resolution_error' => $payout->resolution_error,
        ]);

        if (Setting::get('telegram_alerts_enabled', '1') === '0' || ! $telegram->configured()) {
            return;
        }

        $telegram->send($alertBuilder->build($payout));
    }
}
