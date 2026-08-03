<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment collected on a job. A job can have several — a deposit now and the
 * balance later, possibly via different methods. The job's `amount_collected_cents`
 * (total) and `payment_method` (latest) are kept in sync from these lines by
 * JobLifecycle::recordPayment / deletePayment.
 */
class JobPayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'method' => PaymentMethod::class,
            'collected_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recorded_by');
    }
}
