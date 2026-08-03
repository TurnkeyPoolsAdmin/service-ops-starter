<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One technician contacted within an AiSchedulingSession — see
 * docs/ai-scheduling-spec.md §2.2.
 */
class AiSchedulingAttempt extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_ASKED = 'asked';

    public const STATUS_NEGOTIATING = 'negotiating';

    /**
     * The tech said yes to a whole part of day and we came back with the
     * concrete 2-hour slot we intend to book, giving them a short window to
     * counter. Silence here books the proposed slot — it does NOT move to the
     * next tech (see AiSchedulingService::advance).
     */
    public const STATUS_CONFIRMING_SLOT = 'confirming_slot';

    /**
     * The job is BOOKED and we have asked this tech which warehouse to order
     * the part from. A live status on purpose: it is what routes their answer
     * back to this job, and it keeps them off another session's offer list
     * while the question is open. Bounded by `deadline_at` — the sweep closes
     * it out either way, so a tech is never held for long.
     */
    public const STATUS_AWAITING_WAREHOUSE = 'awaiting_warehouse';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_NO_RESPONSE = 'no_response';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_ESCALATED = 'escalated';

    public const STATUS_SUPERSEDED = 'superseded';

    /** Statuses that make a tech "spoken for" — the one-live-attempt-per-tech invariant. */
    public const LIVE_STATUSES = [
        self::STATUS_QUEUED, self::STATUS_ASKED,
        self::STATUS_NEGOTIATING, self::STATUS_CONFIRMING_SLOT,
        self::STATUS_AWAITING_WAREHOUSE,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'reiterations' => 'integer',
            'asked_at' => 'datetime',
            'deadline_at' => 'datetime',
            'last_inbound_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSchedulingSession::class, 'session_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function turns(): HasMany
    {
        return $this->hasMany(AiSchedulingTurn::class, 'attempt_id')->orderBy('created_at');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }
}
