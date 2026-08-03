<?php

namespace App\Services;

use App\Enums\BankMatchKind;
use App\Models\BankTransaction;
use App\Models\LegacyManualPayment;
use App\Support\LegacyPaymentResolution;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Matches App\Models\LegacyManualPayment rows (the pre-Job-tracking-era CSV
 * import) against real, unmatched App\Models\BankTransaction deposits — same
 * amount+date-window+name-disambiguation shape as
 * App\Services\BankMatcher::findCustomerPayment(), kept as its own
 * independent service rather than folded into BankMatcher (same precedent as
 * VendorChargeReconciler / IgcChargeReconciler each owning their own
 * matching logic).
 *
 * Every write path takes $dryRun (default true) — inspects and reports
 * without touching the database until explicitly told not to. Matches use
 * source='legacy_import' (not 'auto' or 'manual') so they're distinguishable
 * from the live automated matcher or a human click in any later audit, and
 * land as STATUS_SUGGESTED (not auto-confirmed as STATUS_MATCHED) — same
 * conservative precedent findCustomerPayment() itself follows for customer-
 * payment-kind matches: a human still does the final one-click confirm on
 * the ledger, even for a matcher that's confident.
 */
class LegacyPaymentMatcher
{
    /** Legacy payments ready to attempt matching. */
    public function candidates(): Collection
    {
        return LegacyManualPayment::query()->pending()->orderBy('payment_date')->get();
    }

    /**
     * Attempt every pending legacy payment. Returns
     * ['tally' => ['matched'=>n,'ambiguous'=>n,'no_candidate'=>n], 'results' => LegacyPaymentResolution[]].
     */
    public function run(bool $dryRun = true): array
    {
        $tally = ['matched' => 0, 'ambiguous' => 0, 'no_candidate' => 0];
        $results = [];

        foreach ($this->candidates() as $payment) {
            $resolution = $this->matchOne($payment, $dryRun);
            $tally[$resolution->state]++;
            $results[] = $resolution;
        }

        return ['tally' => $tally, 'results' => $results];
    }

    public function matchOne(LegacyManualPayment $payment, bool $dryRun = true): LegacyPaymentResolution
    {
        $candidates = $this->transactionCandidates($payment);

        if ($candidates->isEmpty()) {
            if (! $dryRun) {
                $payment->update(['status' => LegacyManualPayment::STATUS_UNMATCHED]);
            }

            return new LegacyPaymentResolution('no_candidate', $payment, candidates: $candidates);
        }

        // ⚠️ 2026-07-30: a single candidate used to auto-link on amount+date
        // alone, no name check at all — that's how Misti Schwartz's $425.00
        // landed on "Zelle payment from AKIRA NAKAMURA" and Bryan Sprow's
        // $468.00 landed on "CINESKY LLC", two real "confident" matches
        // written live with zero name correspondence. Every candidate set —
        // including a set of one — now has to pass disambiguateByName();
        // amount+date proximity alone was never a safe enough signal to
        // attribute someone's money to them, single candidate or not.
        $byName = $this->disambiguateByName($candidates, $payment);
        if ($byName !== null) {
            if (! $dryRun) {
                $this->link($byName, $payment);
            }

            return new LegacyPaymentResolution('matched', $payment, $byName, $candidates);
        }

        if (! $dryRun) {
            $payment->update(['status' => LegacyManualPayment::STATUS_UNMATCHED]);
        }

        return new LegacyPaymentResolution('ambiguous', $payment, candidates: $candidates);
    }

    /**
     * Sweep incoming, unmatched, posted deposits within the legacy CSV's own
     * date range that have NO candidate at all among ANY legacy row that
     * could plausibly explain a real deposit (paid > $0, not the no_match_
     * expected/cash bucket) — not just the still-pending ones, since a
     * payment merely held for human review still means "something in the
     * CSV explains this transaction, don't pre-emptively exclude it." Only a
     * transaction nothing in the CSV comes close to at all, on amount or
     * date, gets BankTransaction::excludeAsPretracking().
     */
    public function sweepUnexplainedTransactions(bool $dryRun = true): array
    {
        [$rangeStart, $rangeEnd] = $this->csvDateRange();
        if ($rangeStart === null || $rangeEnd === null) {
            return ['excluded' => 0, 'total_in_range' => 0];
        }

        $explainerRows = LegacyManualPayment::query()
            ->whereNotNull('payment_date')
            ->where('paid_cents', '>', 0)
            ->where('status', '!=', LegacyManualPayment::STATUS_NO_MATCH_EXPECTED)
            ->get();

        $tolerance = (int) config('glass.bank_matching.amount_tolerance_cents', 500);
        $days = (int) config('glass.bank_matching.payment_window_days', 5);

        $inRange = BankTransaction::query()
            ->where('pending', false)
            ->where('match_status', BankTransaction::STATUS_UNMATCHED)
            ->whereNull('excluded_at')
            ->where('amount_cents', '<', 0)
            ->get()
            ->filter(fn (BankTransaction $t) => $t->matchDate() >= $rangeStart && $t->matchDate() <= $rangeEnd);

        $excluded = 0;

        foreach ($inRange as $txn) {
            $hasCandidate = $explainerRows->contains(function (LegacyManualPayment $row) use ($txn, $tolerance, $days) {
                $deposit = abs($txn->amount_cents);
                if ($deposit < $row->paid_cents - $tolerance || $deposit > $row->paid_cents + $tolerance) {
                    return false;
                }

                [$start, $end] = $this->windowAround($row->payment_date, $days);

                return $this->withinWindow($txn->matchDate(), $start, $end);
            });

            if (! $hasCandidate) {
                $excluded++;
                if (! $dryRun) {
                    $txn->excludeAsPretracking(
                        "Pre-tracking era (legacy manual payments CSV, {$rangeStart} to {$rangeEnd}) - "
                        .'no candidate row found in the CSV within amount/date tolerance.',
                    );
                }
            }
        }

        return ['excluded' => $excluded, 'total_in_range' => $inRange->count()];
    }

    private function transactionCandidates(LegacyManualPayment $payment): Collection
    {
        if ($payment->paid_cents === null || $payment->payment_date === null) {
            return collect();
        }

        $paid = (int) $payment->paid_cents;
        $tolerance = (int) config('glass.bank_matching.amount_tolerance_cents', 500);
        $days = (int) config('glass.bank_matching.payment_window_days', 5);
        [$start, $end] = $this->windowAround($payment->payment_date, $days);

        return BankTransaction::query()
            ->where('pending', false)
            ->where('match_status', BankTransaction::STATUS_UNMATCHED)
            ->whereNull('excluded_at')
            ->whereBetween('amount_cents', [-($paid + $tolerance), -($paid - $tolerance)])
            ->get()
            ->filter(fn (BankTransaction $t) => $this->withinWindow($t->matchDate(), $start, $end));
    }

    private function disambiguateByName(Collection $candidates, LegacyManualPayment $payment): ?BankTransaction
    {
        $nameTokens = array_filter(
            preg_split('/\s+/', mb_strtolower(trim($payment->customer_name))) ?: [],
            fn (string $t) => mb_strlen($t) >= 3,
        );

        if ($nameTokens === []) {
            return null;
        }

        $matches = $candidates->filter(function (BankTransaction $t) use ($nameTokens) {
            $description = mb_strtolower($t->name.' '.($t->merchant_name ?? ''));

            foreach ($nameTokens as $token) {
                if (str_contains($description, $token)) {
                    return true;
                }
            }

            return false;
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function link(BankTransaction $txn, LegacyManualPayment $payment): void
    {
        $match = $txn->matches()->create([
            'kind' => BankMatchKind::JobPayment->value,
            'legacy_manual_payment_id' => $payment->id,
            'source' => 'legacy_import',
            'note' => 'Legacy CSV import: '.$payment->customer_name.' ('.$payment->payment_date.')',
        ]);

        $txn->update(['match_status' => BankTransaction::STATUS_SUGGESTED]);

        $payment->update([
            'status' => LegacyManualPayment::STATUS_MATCHED,
            'bank_transaction_match_id' => $match->id,
        ]);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function csvDateRange(): array
    {
        return [
            LegacyManualPayment::query()->whereNotNull('payment_date')->min('payment_date'),
            LegacyManualPayment::query()->whereNotNull('payment_date')->max('payment_date'),
        ];
    }

    private function windowAround(string $date, int $days): array
    {
        $day = Carbon::parse($date, config('glass.timezone'));

        return [
            $day->copy()->subDays($days)->startOfDay()->utc(),
            $day->copy()->addDays($days)->endOfDay()->utc(),
        ];
    }

    private function withinWindow(string $matchDate, Carbon $start, Carbon $end): bool
    {
        $parsed = Carbon::parse($matchDate, 'UTC');

        return $parsed->gte($start) && $parsed->lte($end);
    }
}
