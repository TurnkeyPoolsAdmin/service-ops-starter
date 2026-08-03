<?php

namespace App\Services\Telegram;

use App\Models\VendorDocument;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * I3 — monthly (not weekly, owner decision) digest of IGC's return rate:
 * credited dollars against ordered dollars for the month that just ended.
 * At IGC's real volume (~3.4 returns/week on a small base), a weekly cadence
 * is nearly all noise — a single return in a slow week alone clears 30%. A
 * monthly rollup fires roughly 4 of 10 months on the real data, which is
 * honest signal. Shipped as a DIGEST, not an alarm: the credit makes us
 * whole on the part cost, so the real cost behind a high rate is a wasted
 * branch trip and a job delayed a day or two — worth watching, not panicking
 * over. Computed purely from vendor_documents (no vendor_payments join
 * needed) so it works from the invoice-history file alone.
 */
class IgcReturnRateAlert
{
    public function build(): ?string
    {
        $thresholdPct = (float) config('glass.vendors.igc.return_rate_alert_pct', 30);

        $month = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $start = $month->toDateString();
        $end = $month->copy()->endOfMonth()->toDateString();

        $orderedCents = (int) VendorDocument::query()->igc()->invoices()
            ->whereBetween('document_date', [$start, $end])
            ->sum('net_amount_cents');

        if ($orderedCents <= 0) {
            return null;
        }

        $creditedCents = (int) abs((int) VendorDocument::query()->igc()->creditMemos()
            ->whereBetween('document_date', [$start, $end])
            ->sum('net_amount_cents'));

        $pct = round(($creditedCents / $orderedCents) * 100, 1);

        if ($pct < $thresholdPct) {
            return null;
        }

        return "📊 <b>Service Ops — IGC monthly return rate</b>\n\n"
            .$month->format('F Y').': '.Money::format($creditedCents).' credited against '.Money::format($orderedCents)
            ." ordered — <b>{$pct}%</b> by dollar. The credit makes us whole on the part cost — this is a trend "
            .'to watch (wasted branch trips, delayed jobs), not an incident.';
    }
}
