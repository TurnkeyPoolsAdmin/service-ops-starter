<?php

namespace App\Services\Vendors;

use App\Models\BankTransaction;
use App\Models\VendorDocument;
use Illuminate\Support\Collection;

/**
 * Pure result of App\Services\Vendors\VendorChargeReconciler::resolve() — what
 * a Mygrant card charge reconciled to (or why it couldn't), used identically by
 * the reconciler's own persistence step, the Filament status banner, and the
 * Telegram post-charge alert so the three never disagree about the story.
 *
 *  - matched   — the BILLING PERIOD's own document set (settle_date == the
 *                charge's date) sums exactly to the charge; auto-confirmed.
 *  - suggested — a unique subset WITHIN that period sums exactly; needs a click.
 *  - short     — the charge is LESS than the period's document sum: a credit
 *                memo is probably still missing. shortfallCents is the exact
 *                expected amount — computed from the PERIOD sum only, never
 *                from the open backlog (an earlier week's unresolved shortfall
 *                must never roll forward into a later week's number).
 *  - over      — the charge is MORE than the period's document sum: likely a
 *                missing invoice or a sibling ShipTo account. Never auto-resolved.
 *  - ambiguous — more than one subset (within the period, or the fallback
 *                backlog when the period is empty) sums exactly; a human decides.
 *  - no_candidates — no in-scope open Mygrant documents settle by this charge's
 *                date at all (nothing to reconcile against yet).
 *  - unresolved — the period had no documents of its own AND the fallback
 *                backlog search (a genuine straggler that missed its own
 *                settle date) didn't resolve cleanly either. Deliberately NOT
 *                short/over — there is no legitimate period baseline to diff
 *                a whole unrelated backlog against.
 *
 * fullSumCents is the PERIOD's sum when the period had documents; only when
 * the period was empty does it fall back to describing the backlog fallback
 * set's sum (informational only in that case — never fed into a shortfall).
 */
final readonly class ChargeResolution
{
    /**
     * @param  ?Collection<int, VendorDocument>  $documents
     */
    public function __construct(
        public string $state,
        public BankTransaction $transaction,
        public int $chargeCents,
        public ?int $fullSumCents = null,
        public ?int $shortfallCents = null,
        public ?int $overageCents = null,
        public ?Collection $documents = null,
    ) {}

    public function isResolved(): bool
    {
        return in_array($this->state, ['matched', 'suggested'], true);
    }

    public function needsAttention(): bool
    {
        return in_array($this->state, ['short', 'over', 'ambiguous', 'unresolved'], true);
    }
}
