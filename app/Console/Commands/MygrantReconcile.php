<?php

namespace App\Console\Commands;

use App\Services\Vendors\VendorChargeReconciler;
use App\Services\Vendors\VendorLineMatcher;
use Illuminate\Console\Command;

/**
 * Recomputes Mygrant reconciliation: charges <-> documents (VendorChargeReconciler)
 * then billed lines <-> jobs (VendorLineMatcher). Scheduled daily right after
 * bank:suggest-matches (which already routes a Mygrant charge through the same
 * reconciler via BankMatcher::matchOne) — this command exists so a fresh
 * upload (a credit memo that just resolved a shortfall, a new order-history
 * pull) doesn't have to wait for tomorrow's bank pull to re-attempt a charge
 * that's been sitting unmatched. Safe to run any time; every step is
 * idempotent (see VendorChargeReconciler::resolve / VendorLineMatcher::matchOne).
 */
class MygrantReconcile extends Command
{
    protected $signature = 'mygrant:reconcile';

    protected $description = 'Recompute Mygrant charge<->document and line<->job reconciliation.';

    public function handle(VendorChargeReconciler $charges, VendorLineMatcher $lines): int
    {
        $chargeTally = $charges->run();
        $lineTally = $lines->run();

        $this->info('Mygrant charges: '.$this->formatTally($chargeTally));
        $this->info('Mygrant lines: '.$this->formatTally($lineTally));

        return self::SUCCESS;
    }

    private function formatTally(array $tally): string
    {
        $parts = [];
        foreach ($tally as $state => $count) {
            if ($count > 0) {
                $parts[] = "{$count} {$state}";
            }
        }

        return $parts === [] ? 'nothing to do' : implode(', ', $parts);
    }
}
