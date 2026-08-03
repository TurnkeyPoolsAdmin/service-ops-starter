<?php

namespace App\Services\Telegram;

use App\Enums\PartsSupplier;
use App\Models\VendorImport;

/**
 * Monday-morning nag: reminds the team to upload the last couple weeks of
 * Mygrant order history when nothing recent has come in, so charge/line
 * reconciliation always has fresh data. build() returns null (stay quiet)
 * once a recent upload exists — no per-row stamp needed, it just checks the
 * live state each run, same convention as SameDayPartsUnorderedAlert.
 */
class MygrantUploadNagAlert
{
    public function build(): ?string
    {
        $days = (int) config('glass.vendors.mygrant.upload_nag_days', 14);

        $hasRecentUpload = VendorImport::query()
            ->where('supplier', PartsSupplier::Mygrant->value)
            ->where('kind', 'order_history')
            ->where('result', 'ok')
            ->where('created_at', '>=', now()->subDays($days))
            ->exists();

        if ($hasRecentUpload) {
            return null;
        }

        return "📎 <b>Service Ops — Mygrant</b>\n\n"
            ."No order-history upload in the last {$days} days — upload the latest CSV "
            .'(Admin &gt; Finance &gt; Parts Invoices) so charge reconciliation stays current.';
    }
}
