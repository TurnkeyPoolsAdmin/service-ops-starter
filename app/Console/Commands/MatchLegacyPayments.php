<?php

namespace App\Console\Commands;

use App\Models\BankTransaction;
use App\Models\LegacyManualPayment;
use App\Services\LegacyPaymentMatcher;
use App\Support\LegacyPaymentResolution;
use Illuminate\Console\Command;

/**
 * Matches imported legacy CSV payments against real bank transactions, and
 * excludes zero-candidate deposits in the CSV's date range as pre-tracking
 * (same treatment as IGC Layer 4 / Mygrant's coverage-start exclusion) — see
 * App\Services\LegacyPaymentMatcher's doc comment for the exact matching
 * shape and why matches are STATUS_SUGGESTED (a human still confirms),
 * never auto-confirmed.
 *
 * Dry run by default — reports counts, writes nothing. Pass --live to
 * actually create matches / exclude transactions, with a confirmation
 * prompt first.
 */
class MatchLegacyPayments extends Command
{
    protected $signature = 'legacy-payments:match {--live : Write matches and exclusions instead of a dry run}';

    protected $description = 'Match legacy CSV payments against real bank transactions (dry run by default).';

    public function handle(LegacyPaymentMatcher $matcher): int
    {
        $live = (bool) $this->option('live');

        if ($live && ! $this->confirm('This writes matches and pre-tracking exclusions against real data. Continue?')) {
            $this->info('Aborted — nothing written.');

            return self::SUCCESS;
        }

        $needsReviewCount = LegacyManualPayment::query()->needsReview()->count();

        $matchResult = $matcher->run(dryRun: ! $live);
        $sweepResult = $matcher->sweepUnexplainedTransactions(dryRun: ! $live);

        $this->info($live ? 'LIVE RUN — changes written.' : 'DRY RUN — nothing written. Pass --live to apply.');
        $this->newLine();
        $this->line('Confident matches (single candidate, or disambiguated by name): '.$matchResult['tally']['matched']);
        $this->line('Ambiguous (2+ candidates, held - not guessed): '.$matchResult['tally']['ambiguous']);
        $this->line('No bank-transaction candidate at all: '.$matchResult['tally']['no_candidate']);
        $this->line("Held for review (source CSV flagged it, or the date couldn't be parsed): {$needsReviewCount}");
        $this->newLine();
        $this->line('Bank transactions in the CSV\'s own date range with ZERO candidate anywhere in the CSV '
            .'(pre-tracking exclusion): '.$sweepResult['excluded'].' of '.$sweepResult['total_in_range'].' in-range unmatched deposits');

        $this->reportNoCandidateSplit($matchResult['results']);
        $this->reportConfidentMatches($matchResult['results']);
        $this->reportAmbiguous($matchResult['results']);

        return self::SUCCESS;
    }

    /**
     * Splits the 'no_candidate' rows by whether their payment_date predates
     * the bank ledger's own earliest record — same "predates bank data"
     * framing as VendorChargeReconciler::coverageStartDate() (see
     * MYGRANT_HANDOFF.md Q3): a legacy payment from before Plaid was ever
     * pulling transactions was never going to find a match, that's not a
     * matcher failure. Computed live off bank_transactions.date rather than
     * hardcoded, so this stays correct if the ledger's history changes.
     */
    private function reportNoCandidateSplit(array $results): void
    {
        $coverageStart = BankTransaction::query()->whereNotNull('date')->min('date');

        $this->newLine();
        $this->line('--- No-candidate rows vs. bank coverage start ---');
        $this->line('Bank ledger\'s earliest transaction on record: '.($coverageStart ?? 'none (empty ledger)'));

        $before = 0;
        $after = 0;
        $unknownDate = 0;

        foreach ($results as $resolution) {
            if ($resolution->state !== 'no_candidate') {
                continue;
            }

            $date = $resolution->payment->payment_date;
            if ($date === null || $coverageStart === null) {
                $unknownDate++;
            } elseif ($date < $coverageStart) {
                $before++;
            } else {
                $after++;
            }
        }

        $this->line("Before bank coverage start (predates bank data - expected, not a failure): {$before}");
        $this->line("On/after bank coverage start (genuinely unexplained - worth a look): {$after}");
        if ($unknownDate > 0) {
            $this->line("Undated / ledger empty, couldn't classify: {$unknownDate}");
        }
    }

    private function reportConfidentMatches(array $results): void
    {
        $matched = array_values(array_filter($results, fn (LegacyPaymentResolution $r) => $r->state === 'matched'));

        $this->newLine();
        $this->line('--- Confident matches ('.count($matched).') ---');

        foreach ($matched as $resolution) {
            $payment = $resolution->payment;
            $txn = $resolution->transaction;

            $this->line(sprintf(
                '  %s — $%s (%s) -> txn #%d "%s" $%s on %s',
                $payment->customer_name,
                number_format($payment->paid_cents / 100, 2),
                $payment->payment_date,
                $txn->id,
                $txn->name,
                number_format(abs($txn->amount_cents) / 100, 2),
                $txn->matchDate(),
            ));
        }
    }

    private function reportAmbiguous(array $results): void
    {
        $ambiguous = array_values(array_filter($results, fn (LegacyPaymentResolution $r) => $r->state === 'ambiguous'));

        $this->newLine();
        $this->line('--- Ambiguous ('.count($ambiguous).') ---');

        foreach ($ambiguous as $resolution) {
            $payment = $resolution->payment;

            $this->line(sprintf(
                '  %s — $%s (%s):',
                $payment->customer_name,
                number_format($payment->paid_cents / 100, 2),
                $payment->payment_date,
            ));

            foreach ($resolution->candidates as $txn) {
                $this->line(sprintf(
                    '      candidate txn #%d "%s" $%s on %s',
                    $txn->id,
                    $txn->name,
                    number_format(abs($txn->amount_cents) / 100, 2),
                    $txn->matchDate(),
                ));
            }
        }
    }
}
