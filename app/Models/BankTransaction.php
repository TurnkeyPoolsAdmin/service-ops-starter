<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankTransaction extends Model
{
    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_SUGGESTED = 'suggested';

    public const STATUS_MATCHED = 'matched';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        // `date` is intentionally NOT cast (kept a plain 'Y-m-d' string) so the
        // value matches identically on SQLite and MySQL — see the migration note
        // and AdMetric for the same rationale.
        return [
            'amount_cents' => 'integer',
            'pending' => 'boolean',
            'synced_at' => 'datetime',
            'matched_at' => 'datetime',
            'review_flagged' => 'boolean',
            'flagged_at' => 'datetime',
            'excluded_at' => 'datetime',
        ];
    }

    public function excludedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'excluded_by');
    }

    public function plaidItem(): BelongsTo
    {
        return $this->belongsTo(PlaidItem::class);
    }

    public function flaggedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'flagged_by');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BankTransactionMatch::class);
    }

    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'matched_by');
    }

    /**
     * The date reconciliation should key on: Plaid's `authorized_date` (when the
     * transaction was INITIATED) when present, else the posted `date`. Chase
     * populates authorized_date on outgoing Zelle, so a payment sent Jul 10 that
     * posts Jul 13 matches against Jul 10 — the day we actually stamped the payout.
     */
    public function matchDate(): string
    {
        return $this->authorized_date ?: $this->date;
    }

    /**
     * Posted transactions still needing a human: unmatched or auto-suggested,
     * and not excluded (see excludeAsPretracking()) — an excluded row has
     * nothing left to explain it, so it no longer demands attention here.
     */
    public function scopeNeedsReview(Builder $q): Builder
    {
        return $q->where('pending', false)
            ->whereIn('match_status', [self::STATUS_UNMATCHED, self::STATUS_SUGGESTED])
            ->whereNull('excluded_at');
    }

    public function scopeExcluded(Builder $q): Builder
    {
        return $q->whereNotNull('excluded_at');
    }

    public function isExcluded(): bool
    {
        return $this->excluded_at !== null;
    }

    /**
     * Same "pre-tracking, out of scope" treatment as IGC Layer 4 / Mygrant's
     * coverage-start exclusion — removes this transaction from the active
     * "needs review" queue without matching it to anything, because nothing
     * explains it. $by null means an automated sweep/import did this, not a
     * person. Reversible via restoreFromExclusion().
     */
    public function excludeAsPretracking(string $reason, ?int $by = null): void
    {
        $this->update([
            'excluded_at' => now(),
            'excluded_reason' => $reason,
            'excluded_by' => $by,
        ]);
    }

    /** Undo excludeAsPretracking() — brings the transaction back into the active needs-review queue. */
    public function restoreFromExclusion(): void
    {
        $this->update([
            'excluded_at' => null,
            'excluded_reason' => null,
            'excluded_by' => null,
        ]);
    }
}
