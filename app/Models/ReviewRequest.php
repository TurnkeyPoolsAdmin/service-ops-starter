<?php

namespace App\Models;

use App\Support\Timezone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One job's journey through the call-first review flow. Created in
 * `awaiting_call` the instant work is marked complete (or, for a calibration
 * job, the instant ops confirms calibration) — before any sentiment is known.
 * ReviewDripService owns every transition; this model just holds state + the
 * dashboard-facing stage derivation.
 */
class ReviewRequest extends Model
{
    /** Just opened — dispatch hasn't logged the first call yet. */
    public const STATUS_AWAITING_CALL = 'awaiting_call';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REVIEW_LEFT = 'review_left';

    // ---- First call (right after completion) outcomes ----
    public const FIRST_CALL_CONNECTED = 'connected';

    public const FIRST_CALL_NO_ANSWER = 'no_answer';

    public const FIRST_CALL_AUTO = 'auto'; // 7pm deadline fell through with no call logged

    // ---- Second call (after text 2, the old "call gate") outcomes ----
    public const OUTCOME_CONNECTED = 'connected';

    public const OUTCOME_VOICEMAIL = 'voicemail';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'first_call_logged_at' => 'datetime',
            'first_call_skipped_at' => 'datetime',
            'step1_sent_at' => 'datetime',
            'step2_sent_at' => 'datetime',
            'call_logged_at' => 'datetime',
            'step3_sent_at' => 'datetime',
            'final_sent_at' => 'datetime',
            'review_left_at' => 'datetime',
            'thank_you_sent_at' => 'datetime',
            'next_send_at' => 'datetime',
            'paused_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    // ---- Relations ----

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function callLoggedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'call_logged_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function firstCallLoggedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'first_call_logged_by');
    }

    // ---- Scopes ----

    /**
     * Still moving through the flow (paused counts — it can resume).
     * awaiting_call belongs here too — STOP/opt-out must kill a pending first
     * call exactly like it kills a running drip.
     */
    public function scopeLive(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_AWAITING_CALL, self::STATUS_ACTIVE, self::STATUS_PAUSED]);
    }

    /** Rows still waiting on the first call — the Reviews dashboard + Open Tasks queue. */
    public function scopeAwaitingFirstCall(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_AWAITING_CALL);
    }

    /**
     * Both automated texts went out and the call-delay window has elapsed;
     * waiting on the manual phone call. The gate opens `call_after_days` after
     * text 2 (not the instant it sends).
     */
    public function scopeCallNeeded(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('step2_sent_at')
            ->where('step2_sent_at', '<=', self::callGateThreshold())
            ->whereNull('call_outcome')
            ->whereNull('review_left_at');
    }

    /** A text-2 timestamp on or before this moment means the call gate is open. */
    private static function callGateThreshold(): \Carbon\CarbonInterface
    {
        return now()->subDays((int) config('glass.reviews.call_after_days', 2));
    }

    /** Active rows the cron should act on now. */
    public function scopeDueForAdvance(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('next_send_at')
            ->where('next_send_at', '<=', now());
    }

    /**
     * 7:00pm Pacific (configurable) on the row's CREATION day — the deadline
     * after which an un-logged (or skipped) first call auto-falls-through into
     * the regular drip. A row created after the deadline is already past it,
     * so it falls through on the very next sweep.
     */
    public function firstCallDeadlineUtc(): CarbonInterface
    {
        $createdLocalDay = Timezone::toDisplay($this->created_at);

        return Timezone::localDateTimeToUtc(
            $createdLocalDay,
            (string) config('glass.reviews.first_call_deadline_local_time', '19:00'),
        );
    }

    /** Still awaiting a first call and past its 7pm deadline. */
    public function isFirstCallOverdue(): bool
    {
        return $this->status === self::STATUS_AWAITING_CALL
            && now()->gte($this->firstCallDeadlineUtc());
    }

    // ---- Dashboard stage (derived, human-facing) ----

    public function isCallNeeded(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->step2_sent_at !== null
            && $this->step2_sent_at->lte(self::callGateThreshold())
            && $this->call_outcome === null;
    }

    public function stageLabel(): string
    {
        return match ($this->status) {
            self::STATUS_AWAITING_CALL => $this->first_call_skipped_at !== null
                ? 'First call skipped — call today'
                : 'First call needed',
            self::STATUS_SKIPPED => 'Skipped — job went poorly',
            self::STATUS_CANCELED => 'Canceled'.($this->cancel_reason ? ' — '.$this->cancel_reason : ''),
            self::STATUS_REVIEW_LEFT => 'Review left ✓',
            self::STATUS_COMPLETED => 'Finished — all texts sent',
            self::STATUS_PAUSED => 'Paused',
            default => $this->activeStageLabel(),
        };
    }

    /** Either the last step that went out, or the "Call needed" gate. */
    private function activeStageLabel(): string
    {
        if ($this->isCallNeeded()) {
            return 'Call needed';
        }
        if ($this->final_sent_at !== null) {
            return 'Final text sent';
        }
        if ($this->step3_sent_at !== null) {
            return 'Text 3 sent';
        }
        if ($this->call_outcome !== null) {
            return 'Call logged';
        }
        if ($this->step2_sent_at !== null) {
            return 'Text 2 sent';
        }
        if ($this->step1_sent_at !== null) {
            return 'Text 1 sent';
        }

        return 'Not started';
    }

    public function stageColor(): string
    {
        return match ($this->status) {
            self::STATUS_AWAITING_CALL => 'warning',
            self::STATUS_SKIPPED, self::STATUS_COMPLETED => 'gray',
            self::STATUS_CANCELED => 'danger',
            self::STATUS_REVIEW_LEFT => 'success',
            self::STATUS_PAUSED => 'warning',
            default => $this->isCallNeeded() ? 'warning' : 'info',
        };
    }
}
