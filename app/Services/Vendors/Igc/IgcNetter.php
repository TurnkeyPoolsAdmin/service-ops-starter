<?php

namespace App\Services\Vendors\Igc;

use App\Models\VendorDocument;
use App\Models\VendorPayment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Pairs an IGC return (its own invoice/order number, negative total — e.g.
 * `1924097`, `-158.00`) to the ORIGINAL invoice it credits — §0/C2, the
 * correction that replaces rules R3.1 as the primary signal.
 *
 * The invoice-history file mirrors every return TWICE: the return's own row
 * carries a POSITIVE "Credit/Return of 158.00 on 07/23/26" memo, and the
 * original invoice's row carries the NEGATIVE mirror "Credit/Return of
 * -158.00 on 07/23/26" — both already parsed into `vendor_payments` rows
 * (kind=credit) by IgcInvoiceHistoryImporter. So pairing is a lookup, not a
 * search: match a return's positive mirror to an original's negative mirror
 * on (date, amount).
 *
 * Verified against the real 202-row export: 52 credit documents, 52 negative
 * mirrors, 46 pair uniquely on (date, amount) alone. The remaining 6 are
 * three cases of two identical returns landing on the same day (two
 * originals share the same date+amount pair as two credits) — resolved by
 * processing both sides ascending by invoice/document number and greedily
 * assigning each credit the NEAREST LOWER unconsumed original — which
 * reproduces the real pairing exactly (e.g. return 1915921 -> original
 * 1915920, return 1915964 -> original 1915679) and gets all 52/52.
 *
 * Nothing here persists a pairing yet — there is no document-level "paired
 * to" column in THIS pass (order-history import / line-level netting are
 * deferred, see the class doc on IgcLineMatcher's design notes in the build
 * spec). This class is a pure, stateless, recomputed-on-demand read — same
 * idiom as the other condition-shaped signals in this codebase
 * (MygrantChargeMismatch, TranslationStale) — so a super admin's I5 sweep
 * (App\Console\Commands\SweepJobIssues) and the `igc:reconcile` command's
 * tally always agree, and there's nothing to keep in sync.
 */
class IgcNetter
{
    /** Sweep tally for the `igc:reconcile` command. */
    public function run(): array
    {
        $pairs = $this->pairings();

        return [
            'paired' => $pairs->count(),
            'unpaired' => $this->unpairedCredits()->count(),
        ];
    }

    /**
     * Every credit document successfully paired to its original invoice.
     *
     * @return Collection<int, array{credit: VendorDocument, original: VendorDocument}>
     */
    public function pairings(): Collection
    {
        $positives = VendorPayment::query()->igc()->credits()->where('amount_cents', '>', 0)->get();
        $negatives = VendorPayment::query()->igc()->credits()->where('amount_cents', '<', 0)->get();

        $documentIds = $positives->pluck('vendor_document_id')
            ->merge($negatives->pluck('vendor_document_id'))
            ->unique()
            ->filter();
        $documents = VendorDocument::query()->whereIn('id', $documentIds)->get()->keyBy('id');

        $positivesByKey = $positives->groupBy(fn (VendorPayment $p) => $p->payment_date.'|'.$p->amount_cents);
        $negativesByKey = $negatives->groupBy(fn (VendorPayment $p) => $p->payment_date.'|'.abs($p->amount_cents));

        $pairs = collect();
        $consumedOriginalIds = [];

        foreach ($positivesByKey as $key => $creditPayments) {
            $creditDocIds = $creditPayments->pluck('vendor_document_id')->unique()->values();
            $candidateDocIds = ($negativesByKey->get($key) ?? collect())->pluck('vendor_document_id')->unique()->values();

            $number = fn (?int $id) => $id !== null && $documents->has($id) ? (int) $documents[$id]->document_number : 0;

            $sortedCredits = $creditDocIds->sortBy($number)->values();
            $available = $candidateDocIds->sortBy($number)->values()->all();

            foreach ($sortedCredits as $creditId) {
                $creditNumber = $number($creditId);

                // Nearest LOWER unconsumed candidate (largest candidate number
                // still below the credit's own number).
                $best = null;
                foreach ($available as $candidateId) {
                    $candidateNumber = $number($candidateId);
                    if ($candidateNumber < $creditNumber && ($best === null || $candidateNumber > $number($best))) {
                        $best = $candidateId;
                    }
                }
                // Never observed on the real file, but keep a deterministic
                // fallback rather than leaving a resolvable tie unpaired.
                if ($best === null && $available !== []) {
                    $best = $available[0];
                }

                if ($best !== null && $documents->has($creditId) && $documents->has($best)) {
                    $pairs->push(['credit' => $documents[$creditId], 'original' => $documents[$best]]);
                    $consumedOriginalIds[] = $best;
                    $available = array_values(array_filter($available, fn ($v) => $v !== $best));
                }
            }
        }

        // R3.4 fallback: a credit whose own mirror didn't land an exact-key
        // match — widen to +/-30 days, same amount, nearest date (tie by
        // nearest-lower invoice number). Never fires on the real file (all 52
        // pair on an exact date), kept for the rare/future case.
        $pairedCreditIds = $pairs->pluck('credit.id')->all();
        $stillUnpaired = VendorDocument::query()->igc()->creditMemos()
            ->whereNotIn('id', $pairedCreditIds)
            ->get();

        foreach ($stillUnpaired as $credit) {
            $original = $this->widenedMatch($credit, $consumedOriginalIds);
            if ($original !== null) {
                $pairs->push(['credit' => $credit, 'original' => $original]);
                $consumedOriginalIds[] = $original->id;
            }
        }

        return $pairs;
    }

    /**
     * Credit documents with no original found even after the +/-30 day
     * widened search — rules R3.4 / alert I5. Never observed on the real
     * file; if it fires, it's real (see App\Console\Commands\SweepJobIssues).
     *
     * @return Collection<int, VendorDocument>
     */
    public function unpairedCredits(): Collection
    {
        $pairedCreditIds = $this->pairings()->pluck('credit.id')->all();

        return VendorDocument::query()->igc()->creditMemos()
            ->whereNotIn('id', $pairedCreditIds)
            ->get();
    }

    /** +/-30 days, same amount, nearest date wins; ties go to the nearest-lower invoice number. */
    private function widenedMatch(VendorDocument $credit, array $excludeDocumentIds): ?VendorDocument
    {
        $amountCents = abs((int) $credit->net_amount_cents);
        $creditDate = Carbon::parse($credit->document_date ?? $credit->created_at);

        $candidates = VendorPayment::query()->igc()->credits()
            ->where('amount_cents', -$amountCents)
            ->when($excludeDocumentIds !== [], fn ($q) => $q->whereNotIn('vendor_document_id', $excludeDocumentIds))
            ->get()
            ->filter(fn (VendorPayment $p) => abs(Carbon::parse($p->payment_date)->diffInDays($creditDate)) <= 30);

        if ($candidates->isEmpty()) {
            return null;
        }

        $best = $candidates
            ->sortBy(fn (VendorPayment $p) => [
                abs(Carbon::parse($p->payment_date)->diffInDays($creditDate)),
                $p->vendor_document_id,
            ])
            ->first();

        return VendorDocument::find($best->vendor_document_id);
    }
}
