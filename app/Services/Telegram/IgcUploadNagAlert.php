<?php

namespace App\Services\Telegram;

use App\Enums\PartsSupplier;
use App\Models\VendorImport;

/**
 * Monday-morning nag: reminds the team to upload a fresh IGC invoice-history
 * export when nothing recent has come in, so charge reconciliation and
 * netting always have current data. build() returns null (stay quiet) once
 * a recent upload exists — no per-row stamp, same convention as
 * MygrantUploadNagAlert / SameDayPartsUnorderedAlert.
 *
 * ⚠️ The build spec's design covers BOTH IGC exports (order history +
 * invoice history are "one deliverable") — but the order-history importer is
 * DEFERRED (no export file exists yet, see the build spec §3), so there is
 * no `igc_order_history` VendorImport kind to ever check yet and no upload
 * button for it. This nag therefore only tracks the invoice-history upload
 * for now; extend it to check both kinds when the order-history importer
 * ships in the next pass.
 *
 * When it does fire, recommend a 21-day pull, not 7 — returns lag purchases
 * up to 24 days and payments lag invoices up to 32 (real data), so a 7-day
 * pull can contain credits/payments whose originals fall outside the file.
 * Re-processing is idempotent (upsert by fingerprint) — overlapping pulls
 * are safe and expected.
 */
class IgcUploadNagAlert
{
    public function build(): ?string
    {
        $days = (int) config('glass.vendors.igc.upload_nag_days', 14);

        $hasRecentUpload = VendorImport::query()
            ->where('supplier', PartsSupplier::Igc->value)
            ->where('kind', 'igc_invoice_history')
            ->where('result', 'ok')
            ->where('created_at', '>=', now()->subDays($days))
            ->exists();

        if ($hasRecentUpload) {
            return null;
        }

        return "📎 <b>Service Ops — IGC</b>\n\n"
            ."No invoice-history upload in the last {$days} days — pull a fresh export (a 21-day window, not 7 — "
            .'returns and payments can lag their originals by 3-4 weeks) and upload it via Admin &gt; Finance &gt; '
            .'Parts Invoices &gt; IGC so charge reconciliation and netting stay current.';
    }
}
