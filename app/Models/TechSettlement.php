<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit record of one technician balance settlement — see the migration for the
 * full rationale. The live balance is derived from unsettled jobs; this is the
 * permanent history of how each balance was squared up (or carried over).
 */
class TechSettlement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'tech_pay_owed_cents' => 'integer',
            'cash_collected_cents' => 'integer',
            'balance_before_cents' => 'integer',
            'balance_after_cents' => 'integer',
            'job_count' => 'integer',
            'settled_job_ids' => 'array',
            'settled_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technician_id');
    }

    /** The job a `cash_collected` row's cash came from (null on span-many-jobs rows). */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** Set only on a warranty_payout — the return visit this settled. */
    public function warrantyClaim(): BelongsTo
    {
        return $this->belongsTo(WarrantyClaim::class, 'warranty_claim_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recorded_by');
    }

    public function undoneBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'undone_by');
    }

    public function isUndone(): bool
    {
        return $this->undone_at !== null;
    }
}
