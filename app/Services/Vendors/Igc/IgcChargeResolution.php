<?php

namespace App\Services\Vendors\Igc;

use App\Models\BankTransaction;
use App\Models\VendorPayment;
use Illuminate\Support\Collection;

/**
 * Pure result of IgcChargeReconciler::resolve() — used identically by the
 * reconciler's own persistence step, the Parts Invoices IGC tab, and the
 * Telegram alert so all three never disagree about the story.
 *
 *  - matched        — a payment group on the charge's own date sums exactly; auto-confirmed.
 *  - suggested       — a widened (+/-3 day) date's group, or an AUTH#-subgroup on the exact
 *                       date, sums exactly; needs a human click.
 *  - missing_memo    — R2.4: the charge is MORE than the exact-date group — a payment memo
 *                       is probably missing from the report, or the report is stale. Never
 *                       auto-resolved; the fix is to re-pull a wider window.
 *  - credit_applied  — R2.5: the charge is LESS than the exact-date group — some of the
 *                       recorded payment total came off an existing credit balance rather
 *                       than leaving the bank. Check the invoice's Balance column; escalate
 *                       only if unexplained.
 *  - no_group        — R2.6 / alert I1: no payment record at all for this charge's date.
 */
final readonly class IgcChargeResolution
{
    /**
     * @param  ?Collection<int, VendorPayment>  $payments
     */
    public function __construct(
        public string $state,
        public BankTransaction $transaction,
        public int $chargeCents,
        public ?int $groupSumCents = null,
        public ?string $groupDate = null,
        public ?Collection $payments = null,
        public ?int $shortfallCents = null, // missing_memo: charge - group (the missing memo's likely amount)
        public ?int $overageCents = null,   // credit_applied: group - charge
    ) {}

    public function isResolved(): bool
    {
        return in_array($this->state, ['matched', 'suggested'], true);
    }

    public function needsAttention(): bool
    {
        return in_array($this->state, ['missing_memo', 'credit_applied', 'no_group'], true);
    }
}
