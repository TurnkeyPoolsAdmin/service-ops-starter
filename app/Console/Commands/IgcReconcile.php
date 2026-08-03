<?php

namespace App\Console\Commands;

use App\Services\Vendors\Igc\IgcChargeReconciler;
use App\Services\Vendors\Igc\IgcNetter;
use Illuminate\Console\Command;

/**
 * One idempotent IGC sweep, in strict order: NET (pair returns to their
 * originals) -> RECONCILE CHARGES (Chase charge <-> payment groups). Netting
 * runs first per the build spec (matching against unnetted data is far less
 * reliable) even though this pass has no line-level matcher yet to feed
 * (App\Services\Vendors\Igc\IgcLineMatcher is deferred — see the build spec
 * §6) — keeping the order now means the second pass just adds a third step
 * without reshuffling this one.
 *
 * Scheduled daily 06:30 PT, right after bank:suggest-matches (06:25), which
 * already routes a same-morning IGC charge through IgcChargeReconciler as it
 * processes the ledger — this command exists so a fresh invoice-history
 * upload (which might resolve a short/missing-memo charge) doesn't have to
 * wait for tomorrow's bank pull to re-attempt it. Safe to run any time; every
 * step is idempotent (see IgcNetter::pairings / IgcChargeReconciler::resolve).
 */
class IgcReconcile extends Command
{
    protected $signature = 'igc:reconcile';

    protected $description = 'Recompute IGC netting and charge<->payment reconciliation.';

    public function handle(IgcNetter $netter, IgcChargeReconciler $charges): int
    {
        $netTally = $netter->run();
        $chargeTally = $charges->run();

        $this->info('IGC netting: '.$this->formatTally($netTally));
        $this->info('IGC charges: '.$this->formatTally($chargeTally));

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
