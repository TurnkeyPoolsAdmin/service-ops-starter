<?php

namespace App\Models;

use App\Enums\PartsSupplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One parsed memo LINE off a vendor's payment/returns history export — today
 * only IGC (App\Services\Vendors\Igc\IgcInvoiceHistoryImporter writes these;
 * App\Services\Vendors\Igc\IgcChargeReconciler groups kind=payment rows by
 * DATE — never AUTH#, see that class's doc comment — to reconcile a Chase
 * charge; App\Services\Vendors\Igc\IgcNetter pairs kind=credit rows by
 * (date, amount) to net a return against its original invoice).
 *
 * amount_cents is always SIGNED — never read it through abs().
 */
class VendorPayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            // payment_date is a plain 'Y-m-d' string — NOT a date cast. See
            // the migration note (same rationale as vendor_documents.document_date).
            'amount_cents' => 'integer',
        ];
    }

    public function vendorDocument(): BelongsTo
    {
        return $this->belongsTo(VendorDocument::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function scopeIgc(Builder $q): Builder
    {
        return $q->where('supplier', PartsSupplier::Igc->value);
    }

    public function scopePayments(Builder $q): Builder
    {
        return $q->where('kind', 'payment');
    }

    public function scopeCredits(Builder $q): Builder
    {
        return $q->where('kind', 'credit');
    }

    /** Not yet claimed by a reconciled Chase charge. */
    public function scopeUnmatched(Builder $q): Builder
    {
        return $q->whereNull('bank_transaction_id');
    }
}
