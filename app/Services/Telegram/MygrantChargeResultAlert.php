<?php

namespace App\Services\Telegram;

use App\Enums\VendorDocumentType;
use App\Services\Vendors\ChargeResolution;
use App\Services\Vendors\VendorChargeReconciler;
use App\Support\Money;

/**
 * Posts the current state of the latest Mygrant card charge — ties out (stays
 * quiet), short by an exact amount (download the credit memo), or escalate
 * (charge is higher than every open document, likely a missing invoice).
 * Reposts each run while the charge is still short/over/ambiguous — no
 * per-row stamp, same "keep nagging until it's resolved" convention as
 * SameDayPartsUnorderedAlert — and goes quiet the moment it ties out or a
 * human resolves it manually on the ledger.
 */
class MygrantChargeResultAlert
{
    public function __construct(private readonly VendorChargeReconciler $reconciler) {}

    public function build(): ?string
    {
        // latestChargeResolution() re-resolves live while still unmatched — the
        // same idempotent call the daily reconcile pass makes, so if a fresh
        // document now makes it tie out, it flips to matched here too and this
        // run reports nothing.
        $resolution = $this->reconciler->latestChargeResolution();

        if ($resolution === null) {
            return null;
        }

        return match ($resolution->state) {
            'short' => $this->shortMessage($resolution),
            'over' => $this->overMessage($resolution),
            'ambiguous' => $this->ambiguousMessage($resolution),
            'unresolved' => $this->unresolvedMessage($resolution),
            default => null,
        };
    }

    private function shortMessage(ChargeResolution $r): string
    {
        $charge = Money::format($r->chargeCents);
        $shortfall = Money::format((int) $r->shortfallCents);
        $tryNumber = $this->tryInvoiceNumberHint($r);

        return "⚠️ <b>Service Ops — Mygrant charge short</b>\n\n"
            ."Charge {$charge} on {$r->transaction->date} is short {$shortfall} against this week's own invoice(s) — "
            ."a credit memo is probably still missing.{$tryNumber}";
    }

    private function overMessage(ChargeResolution $r): string
    {
        $charge = Money::format($r->chargeCents);
        $overage = Money::format((int) $r->overageCents);

        return "🚨 <b>Service Ops — Mygrant charge OVER</b>\n\n"
            ."Charge {$charge} on {$r->transaction->date} is {$overage} MORE than this week's own invoice(s) — "
            .'likely a missing invoice, or a sibling ShipTo account. Escalate — this never auto-resolves.';
    }

    private function ambiguousMessage(ChargeResolution $r): string
    {
        $charge = Money::format($r->chargeCents);

        return "⚠️ <b>Service Ops — Mygrant charge ambiguous</b>\n\n"
            ."Charge {$charge} on {$r->transaction->date} matches more than one combination of documents — "
            .'pick the right one by hand on the Bank Transactions ledger.';
    }

    /** No document shares this charge's own settle date, and no straggler in the backlog resolves it either — needs a human look, with no safe number to quote. */
    private function unresolvedMessage(ChargeResolution $r): string
    {
        $charge = Money::format($r->chargeCents);

        return "⚠️ <b>Service Ops — Mygrant charge needs review</b>\n\n"
            ."Charge {$charge} on {$r->transaction->date} has no invoice for its own week and no clean match "
            .'in the backlog either — check Parts Invoices by hand.';
    }

    /** The credit memo that would close this out is usually the invoice number + 1 (see Parts Invoices page). */
    private function tryInvoiceNumberHint(ChargeResolution $r): string
    {
        $invoice = $r->documents?->firstWhere('type', VendorDocumentType::Invoice);

        if ($invoice === null || $invoice->document_number === null) {
            return '';
        }

        return ' Try the credit memo numbered right after the invoice ('.$invoice->document_number.').';
    }
}
