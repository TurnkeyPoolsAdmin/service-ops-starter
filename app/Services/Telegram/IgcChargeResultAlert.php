<?php

namespace App\Services\Telegram;

use App\Services\Vendors\Igc\IgcChargeReconciler;
use App\Services\Vendors\Igc\IgcChargeResolution;
use App\Support\Money;

/**
 * Posts the current state of the latest IGC card charge — I1 (no payment
 * record at all for the charge's date) and I2 (charge doesn't add up to the
 * exact-date payment group) — the IGC twin of MygrantChargeResultAlert.
 * Reposts each run while the charge is still unresolved (no per-row stamp,
 * same "keep nagging until it's resolved" convention as SameDayPartsUnorderedAlert)
 * and goes quiet the moment it ties out or a human resolves it manually on
 * the Bank Transactions ledger.
 */
class IgcChargeResultAlert
{
    public function __construct(private readonly IgcChargeReconciler $reconciler) {}

    public function build(): ?string
    {
        // latestChargeResolution() re-resolves live while still unmatched — the
        // same idempotent call the daily igc:reconcile pass makes, so if a
        // fresh upload now makes it tie out, it flips to matched here too and
        // this run reports nothing.
        $resolution = $this->reconciler->latestChargeResolution();

        if ($resolution === null) {
            return null;
        }

        return match ($resolution->state) {
            'no_group' => $this->noGroupMessage($resolution), // I1
            'missing_memo' => $this->missingMemoMessage($resolution), // I2
            'credit_applied' => $this->creditAppliedMessage($resolution), // I2 (informational)
            default => null,
        };
    }

    /** I1: hard alert — possible duplicate draw, wrong account, or the report simply hasn't been pulled yet. */
    private function noGroupMessage(IgcChargeResolution $r): string
    {
        $charge = Money::format($r->chargeCents);

        return "🚨 <b>Service Ops — IGC charge with no payment record</b>\n\n"
            ."Charge {$charge} on {$r->transaction->date} has no matching payment in the IGC invoice history for "
            .'that date (or nearby) at all — pull a fresh export (a 21-day window, not 7 — see Parts Invoices) '
            .'before escalating as a possible duplicate draw or wrong account.';
    }

    /** I2 (R2.4): the charge is more than the exact-date payment group adds up to — a memo is probably missing. */
    private function missingMemoMessage(IgcChargeResolution $r): string
    {
        $charge = Money::format($r->chargeCents);
        $group = Money::format((int) $r->groupSumCents);
        $missing = Money::format((int) $r->shortfallCents);

        return "⚠️ <b>Service Ops — IGC charge short a payment memo</b>\n\n"
            ."Charge {$charge} on {$r->transaction->date} is {$missing} MORE than the {$group} recorded for "
            ."{$r->groupDate} in the invoice history — a payment memo is probably missing, or the report is "
            .'stale. Re-pull a WIDER window (21 days) before escalating further.';
    }

    /** I2 (R2.5): the charge is less than the exact-date group — likely a credit balance absorbed part of it. */
    private function creditAppliedMessage(IgcChargeResolution $r): string
    {
        $charge = Money::format($r->chargeCents);
        $group = Money::format((int) $r->groupSumCents);
        $diff = Money::format((int) $r->overageCents);

        return "ℹ️ <b>Service Ops — IGC charge less than its payment group</b>\n\n"
            ."Charge {$charge} on {$r->transaction->date} is {$diff} LESS than the {$group} recorded for "
            ."{$r->groupDate} — check the invoice's Balance column, part of it may have come off an existing "
            .'credit balance rather than actually leaving the bank. Escalate only if that doesn\'t explain it.';
    }
}
