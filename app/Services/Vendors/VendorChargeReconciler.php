<?php

namespace App\Services\Vendors;

use App\Enums\BankMatchKind;
use App\Models\BankTransaction;
use App\Models\BankTransactionMatch;
use App\Models\Employee;
use App\Models\VendorDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ties a Mygrant card charge (Chase ledger) to the vendor_documents (invoices /
 * credit memos) it settles.
 *
 * THE UNIT OF RECONCILIATION IS THE BILLING PERIOD, NOT THE BACKLOG. Mygrant
 * charges the card once per weekly settle date, and that charge only ever
 * covers the documents that share that EXACT settle_date — never anything
 * left over from an earlier, still-unresolved week. Summing "every open
 * document settling by this date" (the whole unreconciled backlog) was tried
 * first and was wrong: once anything upstream is outstanding, that number
 * just accumulates forever and every later week's "shortfall" becomes the
 * sum of every prior week's problem, not that week's own. Verified against
 * all 17 real production charges — tests/Feature/MygrantReconciliationTest.php.
 *
 * Resolution order, run against the documents whose settle_date EQUALS the
 * charge's own date (BankTransaction::matchDate — the initiation date when
 * Chase gives one):
 *
 *   1. The period's own document set sums EXACTLY to the charge -> auto-
 *      confirmed. Covers both the ordinary one-invoice week and the
 *      two-document week (an invoice plus its own credit memo, OR two
 *      invoices when a mid-week month-end cut pushes one week's settle date
 *      onto the next) — both land the right documents on the same settle_date
 *      already; no special-casing needed for either.
 *   2. Else, a UNIQUE exact-sum subset WITHIN the period -> suggested, never
 *      auto-confirmed. More than one subset ties -> ambiguous.
 *   3. Charge LOWER than the period sum -> do not match. The shortfall is the
 *      exact amount of a still-missing credit memo for THIS period only.
 *   4. Charge HIGHER than the period sum -> never auto-resolved.
 *
 * When the period has NO documents of its own at all, a narrow fallback
 * searches the full open backlog — but ONLY to catch a genuine straggler (a
 * document that missed its own settle date entirely) via an exact or
 * unique-subset match. That fallback NEVER produces a short/over number —
 * summing unrelated periods to compute a shortfall is exactly the bug this
 * class exists to not repeat.
 *
 * Documents that predate the bank ledger's own Mygrant coverage (see
 * coverageStartDate()) are excluded from every candidate set — they have no
 * charge and never will, so they'd only ever pollute a period or the backlog
 * fallback. They're surfaced separately via outOfScopeDocuments() so they're
 * explained on the Parts Invoices page instead of sitting open forever.
 *
 * Linking reuses the same bank_transaction_matches ledger every other kind
 * uses (BankMatchKind::VendorInvoice, one row per document) so the Bank
 * Transactions ledger's existing Confirm / Unmatch actions work on a vendor
 * charge with zero extra code — only the manual "Match" modal needed a new
 * branch (see BankTransactionsTable).
 */
class VendorChargeReconciler
{
    /** Sweep every unmatched Mygrant charge; returns state => count. */
    public function run(): array
    {
        $tally = ['matched' => 0, 'suggested' => 0, 'short' => 0, 'over' => 0, 'ambiguous' => 0, 'no_candidates' => 0, 'unresolved' => 0];

        foreach ($this->candidateCharges() as $txn) {
            $resolution = $this->resolve($txn);
            $tally[$resolution->state] = ($tally[$resolution->state] ?? 0) + 1;
        }

        return $tally;
    }

    /** Posted, unmatched, money-out transactions whose description reads as Mygrant. */
    public function candidateCharges(): Collection
    {
        return BankTransaction::query()
            ->where('pending', false)
            ->where('match_status', BankTransaction::STATUS_UNMATCHED)
            ->where('amount_cents', '>', 0)
            ->orderBy('date')
            ->get()
            ->filter(fn (BankTransaction $t) => $this->isMygrantCharge($t))
            ->values();
    }

    /** The most recent Mygrant charge to ever post, or null when none has. */
    public function latestMygrantCharge(): ?BankTransaction
    {
        return BankTransaction::query()
            ->where('pending', false)
            ->where('amount_cents', '>', 0)
            ->get()
            ->filter(fn (BankTransaction $t) => $this->isMygrantCharge($t))
            ->sortByDesc('date')
            ->first();
    }

    /**
     * The latest Mygrant charge's CURRENT state — read-only once it's already
     * matched/suggested, recomputed live via resolve() while still unmatched.
     * Shared by the Telegram post-charge alert and the Parts Invoices status
     * banner so the two never disagree about the story. Null when no Mygrant
     * charge has ever posted.
     */
    public function latestChargeResolution(): ?ChargeResolution
    {
        $txn = $this->latestMygrantCharge();
        if ($txn === null) {
            return null;
        }

        if ($txn->match_status === BankTransaction::STATUS_MATCHED) {
            return new ChargeResolution('matched', $txn, (int) $txn->amount_cents);
        }

        if ($txn->match_status === BankTransaction::STATUS_SUGGESTED) {
            return new ChargeResolution('suggested', $txn, (int) $txn->amount_cents);
        }

        return $this->resolve($txn);
    }

    public function isMygrantCharge(BankTransaction $txn): bool
    {
        if ($txn->amount_cents <= 0) {
            return false;
        }

        $haystack = mb_strtolower($txn->name.' '.($txn->merchant_name ?? ''));

        foreach ((array) config('glass.vendors.mygrant.charge_needles', []) as $needle) {
            if (str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve ONE charge: primarily against its own billing period's
     * documents (settle_date === the charge's date), falling back to a
     * narrow backlog search ONLY when the period is empty (see class doc).
     * Persists a real link when one is found. Safe to call more than once on
     * the same still-unmatched transaction.
     */
    public function resolve(BankTransaction $txn): ChargeResolution
    {
        $matchDate = $txn->matchDate();
        $charge = (int) $txn->amount_cents;

        $periodDocs = $this->periodDocuments($matchDate);

        if ($periodDocs->isNotEmpty()) {
            return $this->resolveAgainst($txn, $charge, $periodDocs, allowShortOver: true);
        }

        // No documents share this exact settle date — the ONLY remaining
        // possibility is a straggler that missed its own week. Search the
        // full open (in-scope) backlog, but never let it produce a
        // short/over number: that would just be the original bug again.
        $openDocs = $this->openDocuments($matchDate);

        if ($openDocs->isEmpty()) {
            return new ChargeResolution('no_candidates', $txn, $charge);
        }

        $fallback = $this->resolveAgainst($txn, $charge, $openDocs, allowShortOver: false);

        return $fallback->state === 'unresolved_pending'
            ? new ChargeResolution('unresolved', $txn, $charge, fullSumCents: $fallback->fullSumCents)
            : $fallback;
    }

    /**
     * Shared exact/subset resolution against a candidate document set. When
     * $allowShortOver is false (the backlog fallback), a non-exact result is
     * reported as the sentinel 'unresolved_pending' state instead of
     * short/over — resolve() translates that to 'unresolved' with no
     * shortfall/overage number attached.
     */
    private function resolveAgainst(BankTransaction $txn, int $charge, Collection $docs, bool $allowShortOver): ChargeResolution
    {
        $sum = (int) $docs->sum(fn (VendorDocument $d) => $d->effectiveAmountCents());

        if ($sum === $charge) {
            $this->link($txn, $docs, confirmed: true);

            return new ChargeResolution('matched', $txn, $charge, fullSumCents: $sum, documents: $docs);
        }

        $cap = (int) config('glass.vendors.mygrant.subset_search_cap', 10);
        if ($docs->count() <= $cap) {
            $subsets = $this->exactSumSubsets($docs, $charge);

            if ($subsets->count() === 1) {
                $this->link($txn, $subsets->first(), confirmed: false);

                return new ChargeResolution('suggested', $txn, $charge, fullSumCents: $sum, documents: $subsets->first());
            }

            if ($subsets->count() > 1) {
                return new ChargeResolution('ambiguous', $txn, $charge, fullSumCents: $sum, documents: $docs);
            }
        }

        if (! $allowShortOver) {
            return new ChargeResolution('unresolved_pending', $txn, $charge, fullSumCents: $sum);
        }

        if ($charge < $sum) {
            return new ChargeResolution('short', $txn, $charge, fullSumCents: $sum, shortfallCents: $sum - $charge, documents: $docs);
        }

        return new ChargeResolution('over', $txn, $charge, fullSumCents: $sum, overageCents: $charge - $sum, documents: $docs);
    }

    /**
     * Manually closing an old short week with no credit memo available: stamp
     * the accepted difference on ONE document (normally the primary invoice for
     * that week) and re-attempt resolution — effectiveAmountCents() now folds
     * the acceptance in, so a previously-short charge can match on the next call.
     */
    public function acceptDifference(VendorDocument $document, int $differenceCents, string $note, ?Employee $actor): void
    {
        $document->update([
            'accepted_difference_cents' => $differenceCents,
            'accepted_note' => $note,
            'accepted_at' => now(),
            'accepted_by' => $actor?->id,
        ]);
    }

    /**
     * The first date the bank ledger has ANY Mygrant charge on record (Plaid's
     * own coverage start, e.g. an account only connected in April even though
     * Mygrant invoices go back to January) — or an explicit config override
     * (glass.vendors.mygrant.bank_coverage_start). A document settling before
     * this date can never be charged; it's out of scope, not just unpaid.
     * Null when neither is known yet (no charges seen, no override set) — in
     * that case nothing is excluded.
     *
     * Keyed on matchDate() (the authorized/initiated date), NOT the posted
     * date, because that is the date every other comparison in this class
     * uses — a document's settle_date is a Wednesday and the charge for it
     * posts a day later. Taking the posted date here put the very FIRST
     * charge's own invoice one day outside coverage, so the earliest week
     * could never reconcile: real data has the first charge posting 04/02
     * but authorized 04/01, against a document settling 04/01.
     */
    public function coverageStartDate(): ?string
    {
        $configured = config('glass.vendors.mygrant.bank_coverage_start');
        if (filled($configured)) {
            return $configured;
        }

        return BankTransaction::query()
            ->where('amount_cents', '>', 0)
            ->get()
            ->filter(fn (BankTransaction $t) => $this->isMygrantCharge($t))
            ->map(fn (BankTransaction $t) => $t->matchDate())
            ->min();
    }

    public function isOutOfScope(VendorDocument $document): bool
    {
        $coverageStart = $this->coverageStartDate();

        return $coverageStart !== null && $document->settle_date < $coverageStart;
    }

    /**
     * Open Mygrant documents that predate bank coverage and so can never be
     * charged — surfaced on the Parts Invoices page as "predates bank data"
     * rather than left to sit in the open queue unexplained forever.
     */
    public function outOfScopeDocuments(): Collection
    {
        $coverageStart = $this->coverageStartDate();
        if ($coverageStart === null) {
            return collect();
        }

        return VendorDocument::query()
            ->mygrant()
            ->whereNull('charge_matched_at')
            ->where('settle_date', '<', $coverageStart)
            ->orderByDesc('settle_date')
            ->get();
    }

    /** In-scope, unpaid, not-already-linked Mygrant documents settling on EXACTLY this date. */
    private function periodDocuments(string $settleDate): Collection
    {
        return $this->inScopeDocumentsQuery()->where('settle_date', $settleDate)->orderBy('id')->get();
    }

    /** In-scope, unpaid, not-already-linked Mygrant documents settling ON OR BEFORE the given date — the narrow straggler fallback only. */
    private function openDocuments(string $byDate): Collection
    {
        return $this->inScopeDocumentsQuery()->where('settle_date', '<=', $byDate)->orderBy('settle_date')->get();
    }

    private function inScopeDocumentsQuery(): Builder
    {
        $query = VendorDocument::query()
            ->mygrant()
            ->whereNull('charge_matched_at')
            ->whereNotIn('id', $this->linkedDocumentIds());

        $coverageStart = $this->coverageStartDate();
        if ($coverageStart !== null) {
            $query->where('settle_date', '>=', $coverageStart);
        }

        return $query;
    }

    private function linkedDocumentIds(): Collection
    {
        return BankTransactionMatch::query()
            ->where('kind', BankMatchKind::VendorInvoice->value)
            ->whereNotNull('vendor_document_id')
            ->pluck('vendor_document_id');
    }

    /**
     * Every non-empty subset of $docs whose effectiveAmountCents() sums exactly
     * to $target, as a collection of document collections. Bitmask over a
     * capped document count (config subset_search_cap, default 10 -> at most
     * 1023 combinations) so this stays instant.
     *
     * @param  Collection<int, VendorDocument>  $docs
     * @return Collection<int, Collection<int, VendorDocument>>
     */
    private function exactSumSubsets(Collection $docs, int $target): Collection
    {
        $items = $docs->values();
        $n = $items->count();
        $hits = collect();

        for ($mask = 1; $mask < (1 << $n); $mask++) {
            $sum = 0;
            for ($i = 0; $i < $n; $i++) {
                if ($mask & (1 << $i)) {
                    $sum += $items[$i]->effectiveAmountCents();
                }
            }

            if ($sum === $target) {
                $subset = collect();
                for ($i = 0; $i < $n; $i++) {
                    if ($mask & (1 << $i)) {
                        $subset->push($items[$i]);
                    }
                }
                $hits->push($subset);
            }
        }

        return $hits;
    }

    /**
     * @param  Collection<int, VendorDocument>  $docs
     */
    private function link(BankTransaction $txn, Collection $docs, bool $confirmed): void
    {
        foreach ($docs as $doc) {
            $txn->matches()->create([
                'kind' => BankMatchKind::VendorInvoice->value,
                'vendor_document_id' => $doc->id,
                'source' => 'auto',
            ]);
        }

        $txn->update([
            'match_status' => $confirmed ? BankTransaction::STATUS_MATCHED : BankTransaction::STATUS_SUGGESTED,
            'matched_at' => $confirmed ? now() : null,
        ]);

        if ($confirmed) {
            VendorDocument::whereIn('id', $docs->pluck('id'))->update(['charge_matched_at' => now()]);
        }
    }
}
