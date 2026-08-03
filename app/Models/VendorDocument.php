<?php

namespace App\Models;

use App\Enums\PartsSupplier;
use App\Enums\VendorDocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Mygrant billing document: a weekly invoice, a credit memo, or the
 * current OPEN (uninvoiced) period — see the migration's class doc comment
 * for why an open period is represented as a row here at all.
 *
 * Deliberately thin: reconciling this against a Chase charge (isShort(),
 * variance, etc.) belongs to the next agent's charge reconciler, not here.
 */
class VendorDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            // document_date / cut_date / settle_date are plain 'Y-m-d'
            // strings — NOT date casts. See the migration note.
            'type' => VendorDocumentType::class,
            'net_amount_cents' => 'integer',
            // IGC-only (its own running Balance column) — always null on a
            // Mygrant document. See the migration note.
            'balance_cents' => 'integer',
            'line_count' => 'integer',
            'charge_matched_at' => 'datetime',
            'accepted_difference_cents' => 'integer',
            'accepted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VendorDocumentLine::class);
    }

    /** Parsed payment/credit memo lines off IGC's invoice-history export (App\Models\VendorPayment). */
    public function payments(): HasMany
    {
        return $this->hasMany(VendorPayment::class);
    }

    /** Lines credited back by THIS document (only populated on a credit memo). */
    public function creditedLines(): HasMany
    {
        return $this->hasMany(VendorDocumentLine::class, 'credit_document_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'accepted_by');
    }

    /** Not yet reconciled against a Chase charge. */
    public function scopeUnpaid(Builder $q): Builder
    {
        return $q->whereNull('charge_matched_at');
    }

    public function scopeSettledBy(Builder $q, string $date): Builder
    {
        return $q->where('settle_date', '<=', $date);
    }

    public function scopeInvoices(Builder $q): Builder
    {
        return $q->where('type', VendorDocumentType::Invoice->value);
    }

    public function scopeCreditMemos(Builder $q): Builder
    {
        return $q->where('type', VendorDocumentType::CreditMemo->value);
    }

    public function scopeMygrant(Builder $q): Builder
    {
        return $q->where('supplier', PartsSupplier::Mygrant->value);
    }

    public function scopeIgc(Builder $q): Builder
    {
        return $q->where('supplier', PartsSupplier::Igc->value);
    }

    /**
     * What this document actually settles for, once a human has accepted a
     * difference (an old short week with no credit memo to explain it) —
     * net_amount_cents plus the accepted adjustment. Every sum the charge
     * reconciler computes reads THIS, not the raw net_amount_cents, so
     * accepting a difference is what lets a stuck charge match afterward.
     */
    public function effectiveAmountCents(): int
    {
        return (int) $this->net_amount_cents + (int) ($this->accepted_difference_cents ?? 0);
    }

    /** "Invoice I05061146-0" / "Credit memo I05061147-0" / "Open period (uninvoiced)". */
    public function documentLabel(): string
    {
        if ($this->document_number === null) {
            return 'Open period (uninvoiced)';
        }

        return $this->type->label().' '.$this->document_number;
    }

    public function hasAcceptedDifference(): bool
    {
        return $this->accepted_at !== null;
    }
}
