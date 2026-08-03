<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One balance transaction inside a resolved StripePayout — see that
 * migration's class doc comment. amount fields are Stripe's own numbers for
 * this one line, not an estimate.
 */
class StripePayoutLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'gross_cents' => 'integer',
            'fee_cents' => 'integer',
            'net_cents' => 'integer',
        ];
    }

    public function stripePayout(): BelongsTo
    {
        return $this->belongsTo(StripePayout::class);
    }

    public function jobPayment(): BelongsTo
    {
        return $this->belongsTo(JobPayment::class);
    }

    public function scopeCharges(Builder $q): Builder
    {
        return $q->where('type', 'charge');
    }

    /** A charge-type line that never matched a job_payments row. */
    public function isUnresolvedCharge(): bool
    {
        return $this->type === 'charge' && $this->job_payment_id === null;
    }
}
