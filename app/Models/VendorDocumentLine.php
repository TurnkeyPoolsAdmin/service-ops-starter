<?php

namespace App\Models;

use App\Enums\VendorLineStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One order-history line (one glass part / consumable on one order) — see
 * the migration's class doc comment for why `order_line` is the join key
 * across order history, invoices, and credit memos.
 *
 * match_status/match_source/matched_at/matched_by/suggested_job_id/
 * variance_cents/no_job_reason exist for the NEXT agent's job matcher; this
 * (ingest) part of the build only ever writes their column defaults.
 */
class VendorDocumentLine extends Model
{
    // Mirrors BankTransaction::STATUS_* — plain string consts, not an enum,
    // since match_status is a matching-WORKFLOW field (like
    // bank_transactions.match_status), not a business-domain classification.
    public const MATCH_UNMATCHED = 'unmatched';

    public const MATCH_SUGGESTED = 'suggested';

    public const MATCH_MATCHED = 'matched';

    // Deliberately not "unmatched" — a human/matcher looked and decided no
    // job applies (e.g. a returned part, or a genuine shop-supplies line).
    public const MATCH_NO_JOB = 'no_job';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            // order_date / confirmed_date / invoice_date / cut_date are plain
            // 'Y-m-d' strings — NOT date casts. See the vendor_documents
            // migration note for why.
            'status' => VendorLineStatus::class,
            'needs_confirmation_date' => 'boolean',
            'vehicle_years_from' => 'integer',
            'vehicle_years_to' => 'integer',
            'quantity_ordered' => 'decimal:2',
            'quantity_delivered' => 'decimal:2',
            'unit_price_cents' => 'integer',
            'line_total_cents' => 'integer',
            'returned_at' => 'datetime',
            'matched_at' => 'datetime',
            'variance_cents' => 'integer',
        ];
    }

    public function vendorDocument(): BelongsTo
    {
        return $this->belongsTo(VendorDocument::class);
    }

    /** The credit memo that returned this line, when status is Returned. */
    public function creditDocument(): BelongsTo
    {
        return $this->belongsTo(VendorDocument::class, 'credit_document_id');
    }

    /**
     * The positive line THIS (negative, returned) line reverses — the
     * line-level counterpart to credit_document_id's document-level pairing.
     * Set by a future line-matcher once both sides of a return have a real
     * part code to pair on (see App\Services\Vendors\Igc\IgcNetter's class
     * doc comment); null until then.
     */
    public function reversesLine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_line_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function suggestedJob(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'suggested_job_id');
    }

    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'matched_by');
    }

    public function scopeBilled(Builder $q): Builder
    {
        return $q->where('status', VendorLineStatus::Billed->value);
    }

    /** Billed lines still needing (or only tentatively having) a job match. */
    public function scopeNeedsJob(Builder $q): Builder
    {
        return $q->where('status', VendorLineStatus::Billed->value)
            ->whereIn('match_status', [self::MATCH_UNMATCHED, self::MATCH_SUGGESTED]);
    }

    public function scopeReturned(Builder $q): Builder
    {
        return $q->where('status', VendorLineStatus::Returned->value);
    }
}
