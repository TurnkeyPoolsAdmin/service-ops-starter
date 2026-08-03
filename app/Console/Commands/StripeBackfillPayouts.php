<?php

namespace App\Console\Commands;

use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripePayoutResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * One-time (but safely rerunnable) backfill of Stripe payouts that predate
 * the payout.paid webhook being configured — GET /v1/payouts, paginated, one
 * StripePayoutResolver::handlePayoutPaid() call per payout.
 *
 * Never reprocesses a payout the live webhook (or an earlier backfill run)
 * already resolved: handlePayoutPaid() upserts the stripe_payouts row on the
 * unique stripe_payout_id (idempotent — a payout the webhook already created
 * is just refreshed on status/amount, never duplicated), and its inner call
 * to attemptResolution() is itself a no-op once resolution_status is
 * resolved or partial (see that method's own doc comment). So running this
 * command twice, or running it while the webhook is also live, never
 * duplicates work or data — the only "wasted" work on a rerun is the
 * /v1/payouts list call itself.
 */
class StripeBackfillPayouts extends Command
{
    protected $signature = 'stripe:backfill-payouts {--since= : Only backfill payouts whose arrival_date is on/after this Y-m-d date}';

    protected $description = 'Backfill historical Stripe payouts (GET /v1/payouts) that predate the payout.paid webhook.';

    public function handle(StripeClient $stripe, StripePayoutResolver $resolver): int
    {
        if (! $stripe->configured()) {
            $this->error('Stripe is not configured — add the secret key in Admin -> Integrations.');

            return self::FAILURE;
        }

        $since = $this->option('since');

        try {
            $payouts = $stripe->listAllPayouts();
        } catch (\Throwable $e) {
            $this->error("Failed to list Stripe payouts: {$e->getMessage()}");

            return self::FAILURE;
        }

        $processed = 0;
        $skipped = 0;

        foreach ($payouts as $payoutObject) {
            if ($since !== null && ! $this->onOrAfter($payoutObject, $since)) {
                $skipped++;

                continue;
            }

            $resolver->handlePayoutPaid($payoutObject);
            $processed++;
        }

        $this->info("Backfilled {$processed} Stripe payout(s)".($skipped > 0 ? ", skipped {$skipped} before --since" : '').'.');

        return self::SUCCESS;
    }

    private function onOrAfter(array $payoutObject, string $since): bool
    {
        if (! isset($payoutObject['arrival_date'])) {
            return true; // no date to filter on — don't silently drop it
        }

        $arrivalDate = Carbon::createFromTimestamp((int) $payoutObject['arrival_date'])->utc()->toDateString();

        return $arrivalDate >= $since;
    }
}
