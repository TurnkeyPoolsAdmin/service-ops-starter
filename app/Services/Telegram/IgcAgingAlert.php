<?php

namespace App\Services\Telegram;

use App\Models\VendorDocument;
use App\Support\Money;

/**
 * Layer 5 aging (build spec §7) — an IGC invoice whose balance is still
 * non-zero more than `vendors.igc.aging_alert_days` (30, not the rules
 * doc's 14 — see the config comment for the measured distribution) after its
 * own date. Framed deliberately as a "look-at", not a failure — IGC's own
 * payment cadence is irregular (observed gaps of 4-59 days between draws,
 * rules §1.3), so a healthy invoice sitting open for a few weeks is normal.
 * Reposts the live list every run (no per-row stamp) — an invoice drops off
 * the moment it's paid down to zero or a payment memo lands.
 */
class IgcAgingAlert
{
    public function build(): ?string
    {
        $days = (int) config('glass.vendors.igc.aging_alert_days', 30);
        $cutoff = now()->subDays($days)->toDateString();

        $aging = VendorDocument::query()->igc()->invoices()
            ->whereNotNull('balance_cents')
            ->where('balance_cents', '!=', 0)
            ->where('document_date', '<=', $cutoff)
            ->orderBy('document_date')
            ->get();

        if ($aging->isEmpty()) {
            return null;
        }

        $lines = $aging
            ->map(fn (VendorDocument $d) => '- '.$d->documentLabel().' — '.Money::format((int) $d->balance_cents)
                .' open since '.$d->document_date)
            ->implode("\n");

        $total = Money::format((int) $aging->sum('balance_cents'));

        return "👀 <b>Service Ops — IGC aging (look-at, not a failure)</b>\n\n"
            ."{$aging->count()} invoice(s) with a balance open more than {$days} days — {$total} total:\n{$lines}";
    }
}
