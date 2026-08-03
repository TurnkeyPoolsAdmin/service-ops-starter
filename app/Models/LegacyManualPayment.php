<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row from the pre-Job-tracking era's hand-kept payments CSV (Sept 2025 -
 * Apr 2026) — see the migration's class doc comment for why this is its own
 * flat table rather than a Job/JobPayment.
 */
class LegacyManualPayment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_NO_MATCH_EXPECTED = 'no_match_expected';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_UNMATCHED = 'unmatched';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            // payment_date is a plain 'Y-m-d' string, not a date cast — same
            // convention as bank_transactions.date elsewhere in this app.
            'charged_cents' => 'integer',
            'paid_cents' => 'integer',
            'balance_cents' => 'integer',
        ];
    }

    public function bankTransactionMatch(): BelongsTo
    {
        return $this->belongsTo(BankTransactionMatch::class);
    }

    /** Ready to attempt matching. */
    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING);
    }

    /** Held for a human — source CSV flagged it, or the date couldn't be parsed. */
    public function scopeNeedsReview(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_NEEDS_REVIEW);
    }

    public function isMatched(): bool
    {
        return $this->status === self::STATUS_MATCHED;
    }
}
