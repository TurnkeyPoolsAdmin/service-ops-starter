<?php

namespace App\Models;

use App\Services\AiScheduling\SchedulingConstraint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One "Schedule w/ AI" attempt at a job — see docs/ai-scheduling-spec.md §2.1.
 */
class AiSchedulingSession extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_SCHEDULED = 'scheduled';

    /**
     * The negotiation succeeded in SIMULATE mode: a tech accepted, but
     * nothing was written to the job and nothing was sent. Kept distinct from
     * `scheduled` so "did this touch a real job?" is answerable from this one
     * column rather than inferred from tech_mode.
     */
    public const STATUS_SIMULATED = 'simulated';

    public const STATUS_EXHAUSTED = 'exhausted';

    public const STATUS_CANCELED = 'canceled';

    public const MODE_SIMULATE = 'simulate';

    public const MODE_APPROVE = 'approve';

    public const MODE_LIVE = 'live';

    public const MODE_OFF = 'off';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'constraint' => 'array',
            'original_constraint' => 'array',
            'tech_order' => 'array',
            'retarget_count' => 'integer',
            'resolved_at' => 'datetime',
            'scheduled_at_utc' => 'datetime',
            'cost_cents_micro' => 'integer',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function resolvedTechnician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'resolved_technician_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function currentAttempt(): BelongsTo
    {
        return $this->belongsTo(AiSchedulingAttempt::class, 'current_attempt_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(AiSchedulingAttempt::class, 'session_id')->orderBy('position');
    }

    public function turns(): HasMany
    {
        return $this->hasMany(AiSchedulingTurn::class, 'session_id')->orderBy('created_at');
    }

    /**
     * The dispatcher's own contact list for this session — employee ids in the
     * order they dragged them into, or null when they left the roster ranking
     * alone. Both the ORDER and the MEMBERSHIP count: a tech dragged out of
     * the list is not contacted for this job at all (TechOutreach).
     *
     * @return array<int, int>|null
     */
    public function techOrder(): ?array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter(
            $this->tech_order ?? [],
            fn ($id) => filled($id),
        ))));

        return $ids === [] ? null : $ids;
    }

    public function constraintObject(): SchedulingConstraint
    {
        return SchedulingConstraint::fromArray($this->constraint);
    }

    public function originalConstraintObject(): SchedulingConstraint
    {
        return SchedulingConstraint::fromArray($this->original_constraint);
    }

    public function isLive(): bool
    {
        return in_array($this->status, [self::STATUS_RUNNING, self::STATUS_PAUSED], true);
    }

    public function isClosed(): bool
    {
        return ! $this->isLive() && $this->status !== self::STATUS_DRAFT;
    }
}
