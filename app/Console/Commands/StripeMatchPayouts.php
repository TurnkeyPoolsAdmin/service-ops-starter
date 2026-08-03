<?php

namespace App\Console\Commands;

use App\Services\Stripe\StripePayoutReconciler;
use Illuminate\Console\Command;

/**
 * Links resolved/partial StripePayout rows to their actual Chase deposit —
 * see StripePayoutReconciler's own doc comment for the exact-amount +
 * arrival-date (small window fallback) matching rule and why it only
 * stamps bank_transaction_id rather than creating a full match. Scheduled
 * right after bank:suggest-matches (routes/console.php).
 */
class StripeMatchPayouts extends Command
{
    protected $signature = 'stripe:match-payouts';

    protected $description = "Link resolved Stripe payouts to their actual Chase deposit, by exact amount + arrival date.";

    public function handle(StripePayoutReconciler $reconciler): int
    {
        $tally = $reconciler->run();

        $this->info(sprintf(
            'Stripe payout matching: %d matched, %d ambiguous, %d no candidate.',
            $tally['matched'], $tally['ambiguous'], $tally['no_candidate'],
        ));

        return self::SUCCESS;
    }
}
