<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Stripe payout — see the migration's class doc comment for the
 * two-phase (webhook creates it, App\Services\Stripe\StripePayoutResolver
 * resolves it) design.
 */
class StripePayout extends Model
{
    public const STATUS_PENDING_RESOLUTION = 'pending_resolution';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_RESOLUTION_FAILED = 'resolution_failed';

    public const STATUS_RESOLUTION_EXHAUSTED = 'resolution_exhausted';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            // arrival_date is a plain 'Y-m-d' string — NOT a date cast. See
            // the migration note.
            'amount_cents' => 'integer',
            'resolution_attempts' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StripePayoutLine::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function scopeNeedsResolution(Builder $q): Builder
    {
        return $q->whereIn('resolution_status', [self::STATUS_PENDING_RESOLUTION, self::STATUS_RESOLUTION_FAILED]);
    }

    /** Not yet linked to the Chase deposit it should eventually match. */
    public function scopeUnmatched(Builder $q): Builder
    {
        return $q->whereNull('bank_transaction_id');
    }

    public function isResolved(): bool
    {
        return $this->resolution_status === self::STATUS_RESOLVED;
    }

    public function isPartial(): bool
    {
        return $this->resolution_status === self::STATUS_PARTIAL;
    }
}
