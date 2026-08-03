<?php

namespace App\Services\Vendors\Igc;

use App\Enums\BankMatchKind;
use App\Models\BankTransaction;
use App\Models\BankTransactionMatch;
use App\Models\VendorDocument;
use App\Models\VendorPayment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Ties an IGC card charge (Chase ledger, descriptor "IGC LOS ANGELES" /
 * "IMPORT GLASS", card mask 7532) to the vendor_payments it settles.
 *
 * ⚠️ §0/C1 — the single most important correction in this build: payment
 * groups are grouped by DATE, never by AUTH#. The rules doc's "group by
 * AUTH#, fall back to date" is WRONG and breaks a real charge the owner
 * personally validated: charge 03/16 * $178.49 is made of an AUTH-05507G
 * pair summing $100.25 plus one no-AUTH memo of $78.24 — AUTH-first grouping
 * produces two groups, NEITHER equal to $178.49. Grouping strictly by
 * payment date reproduces all 8 owner-validated charges exactly (see
 * tests/Feature/IgcChargeReconciliationTest.php). AUTH# is stored metadata
 * and a TIEBREAK only (the guard below), never the primary key.
 *
 * Resolution order per charge (BankTransaction::matchDate() — Chase's own
 * initiation date when it gives one):
 *
 *   1. The group of unmatched vendor_payments (kind=payment) on the charge's
 *      OWN date sums EXACTLY to the charge (R2.2, zero tolerance) -> auto-confirmed.
 *   2. Else widen to the group on any date within +/-config days (Chase's
 *      post date can lag IGC's own payment date) whose sum is exact -> suggested,
 *      never auto-confirmed.
 *   3. Guard (never observed on the real file): the exact date carries payments
 *      under two or more distinct AUTH#s and neither the full date nor the
 *      widened search matched -> try each AUTH# subgroup on the exact date ->
 *      suggested only.
 *   4. R2.4 charge > the exact-date group -> 'missing_memo': a payment memo is
 *      probably missing, or the report is stale. Never auto-resolved — the fix
 *      is to re-pull a WIDER window, not to escalate immediately.
 *   5. R2.5 charge < the exact-date group -> 'credit_applied': part of the
 *      recorded total came off an existing credit balance rather than actually
 *      leaving the bank. Check the invoice's Balance column.
 *   6. No payment record for the exact date at all -> 'no_group' (alert I1).
 *
 * Linking reuses the exact same bank_transaction_matches ledger Mygrant's
 * VendorChargeReconciler uses (BankMatchKind::VendorInvoice, one row per
 * document) — a document is considered "claimed" by ANY existing
 * bank_transaction_matches row for it (suggested or confirmed), the same
 * exclusion signal Mygrant's own openDocuments()/linkedDocumentIds() use, so
 * the Bank Transactions ledger's existing Confirm/Unmatch/manual-Match
 * actions work on an IGC charge with no per-vendor UI branching.
 * vendor_payments.bank_transaction_id is stamped as an audit trail once a
 * match is CONFIRMED (R2.3) — see BankTransactionsTable::stampVendorDocumentsCharged,
 * extended to cover IGC payments too.
 */
class IgcChargeReconciler
{
    /** Sweep every unmatched IGC charge; returns state => count. */
    public function run(): array
    {
        $tally = ['matched' => 0, 'suggested' => 0, 'missing_memo' => 0, 'credit_applied' => 0, 'no_group' => 0];

        foreach ($this->candidateCharges() as $txn) {
            $resolution = $this->resolve($txn);
            $tally[$resolution->state] = ($tally[$resolution->state] ?? 0) + 1;
        }

        return $tally;
    }

    /** Posted, unmatched, money-out transactions whose description reads as IGC. */
    public function candidateCharges(): Collection
    {
        return BankTransaction::query()
            ->where('pending', false)
            ->where('match_status', BankTransaction::STATUS_UNMATCHED)
            ->where('amount_cents', '>', 0)
            ->orderBy('date')
            ->get()
            ->filter(fn (BankTransaction $t) => $this->isIgcCharge($t))
            ->values();
    }

    /** The most recent IGC charge to ever post, or null when none has. */
    public function latestIgcCharge(): ?BankTransaction
    {
        return BankTransaction::query()
            ->where('pending', false)
            ->where('amount_cents', '>', 0)
            ->get()
            ->filter(fn (BankTransaction $t) => $this->isIgcCharge($t))
            ->sortByDesc('date')
            ->first();
    }

    /**
     * The latest IGC charge's CURRENT state — read-only once already
     * matched/suggested, recomputed live via resolve() while still
     * unmatched. Shared by the Telegram alert and the Parts Invoices IGC
     * tab's status banner. Null when no IGC charge has ever posted.
     */
    public function latestChargeResolution(): ?IgcChargeResolution
    {
        $txn = $this->latestIgcCharge();
        if ($txn === null) {
            return null;
        }

        if ($txn->match_status === BankTransaction::STATUS_MATCHED) {
            return new IgcChargeResolution('matched', $txn, (int) $txn->amount_cents);
        }

        if ($txn->match_status === BankTransaction::STATUS_SUGGESTED) {
            return new IgcChargeResolution('suggested', $txn, (int) $txn->amount_cents);
        }

        return $this->resolve($txn);
    }

    public function isIgcCharge(BankTransaction $txn): bool
    {
        if ($txn->amount_cents <= 0) {
            return false;
        }

        $haystack = mb_strtolower($txn->name.' '.($txn->merchant_name ?? ''));

        foreach ((array) config('glass.vendors.igc.charge_needles', []) as $needle) {
            if (str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve ONE charge against unmatched vendor_payments, applying the
     * rules above and persisting a real link when one is found (auto/suggested).
     * Safe to call more than once on the same still-unmatched transaction.
     */
    public function resolve(BankTransaction $txn): IgcChargeResolution
    {
        $matchDate = $txn->matchDate();
        $charge = (int) $txn->amount_cents;

        $exactGroup = $this->unmatchedPaymentsOnDate($matchDate);
        $exactSum = (int) $exactGroup->sum('amount_cents');

        // Rule 1: the exact date, exactly.
        if ($exactGroup->isNotEmpty() && $exactSum === $charge) {
            $this->link($txn, $exactGroup, confirmed: true);

            return new IgcChargeResolution('matched', $txn, $charge, groupSumCents: $exactSum, groupDate: $matchDate, payments: $exactGroup);
        }

        // Rule 2: widen +/-N days for a single date whose group sums exactly.
        $windowDays = (int) config('glass.vendors.igc.charge_date_window_days', 3);
        foreach ($this->datesAround($matchDate, $windowDays) as $date) {
            $group = $this->unmatchedPaymentsOnDate($date);
            $sum = (int) $group->sum('amount_cents');

            if ($group->isNotEmpty() && $sum === $charge) {
                $this->link($txn, $group, confirmed: false);

                return new IgcChargeResolution('suggested', $txn, $charge, groupSumCents: $sum, groupDate: $date, payments: $group);
            }
        }

        // Rule 3 (guard, never observed): the exact date carries 2+ distinct
        // AUTH#s — try each AUTH# subgroup.
        if ($exactGroup->isNotEmpty()) {
            $authCodes = $exactGroup->pluck('auth_code')->filter()->unique();

            if ($authCodes->count() > 1) {
                foreach ($authCodes as $auth) {
                    $subgroup = $exactGroup->where('auth_code', $auth)->values();
                    $subSum = (int) $subgroup->sum('amount_cents');

                    if ($subSum === $charge) {
                        $this->link($txn, $subgroup, confirmed: false);

                        return new IgcChargeResolution('suggested', $txn, $charge, groupSumCents: $subSum, groupDate: $matchDate, payments: $subgroup);
                    }
                }
            }
        }

        // Rule 6: nothing recorded for this date at all.
        if ($exactGroup->isEmpty()) {
            return new IgcChargeResolution('no_group', $txn, $charge);
        }

        // Rule 4: charge > group — a memo is probably missing, or the report is stale.
        if ($charge > $exactSum) {
            return new IgcChargeResolution('missing_memo', $txn, $charge, groupSumCents: $exactSum, groupDate: $matchDate, payments: $exactGroup, shortfallCents: $charge - $exactSum);
        }

        // Rule 5: charge < group — some of it came off an existing credit balance.
        return new IgcChargeResolution('credit_applied', $txn, $charge, groupSumCents: $exactSum, groupDate: $matchDate, payments: $exactGroup, overageCents: $exactSum - $charge);
    }

    /** Unmatched (kind=payment) vendor_payments on an exact date, whose owning document isn't already claimed by another charge. */
    private function unmatchedPaymentsOnDate(string $date): Collection
    {
        return VendorPayment::query()
            ->igc()
            ->payments()
            ->where('payment_date', $date)
            ->whereNotIn('vendor_document_id', $this->linkedDocumentIds())
            ->get();
    }

    /** +/-N days around a date, nearest first, excluding the date itself. */
    private function datesAround(string $date, int $days): array
    {
        $base = Carbon::parse($date);
        $out = [];

        for ($i = 1; $i <= $days; $i++) {
            $out[] = $base->copy()->subDays($i)->toDateString();
            $out[] = $base->copy()->addDays($i)->toDateString();
        }

        return $out;
    }

    /** Documents (of ANY supplier) already claimed by an existing vendor-invoice match — same exclusion signal Mygrant's reconciler uses. */
    private function linkedDocumentIds(): Collection
    {
        return BankTransactionMatch::query()
            ->where('kind', BankMatchKind::VendorInvoice->value)
            ->whereNotNull('vendor_document_id')
            ->pluck('vendor_document_id');
    }

    /**
     * @param  Collection<int, VendorPayment>  $payments
     */
    private function link(BankTransaction $txn, Collection $payments, bool $confirmed): void
    {
        $documentIds = $payments->pluck('vendor_document_id')->filter()->unique()->values();

        foreach ($documentIds as $docId) {
            $txn->matches()->create([
                'kind' => BankMatchKind::VendorInvoice->value,
                'vendor_document_id' => $docId,
                'source' => 'auto',
            ]);
        }

        $txn->update([
            'match_status' => $confirmed ? BankTransaction::STATUS_MATCHED : BankTransaction::STATUS_SUGGESTED,
            'matched_at' => $confirmed ? now() : null,
        ]);

        if ($confirmed) {
            VendorDocument::whereIn('id', $documentIds)->update(['charge_matched_at' => now()]);
            VendorPayment::whereIn('id', $payments->pluck('id'))->update(['bank_transaction_id' => $txn->id]);
        }
    }
}
