<?php

namespace App\Models;

use App\Enums\Language;
use App\Services\Messaging\TemplateRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One warranty / fix-needed return visit on a completed job. Opened from the
 * job's actions, worked on the Warranty dashboard, scheduled (unpaid tech
 * visit + one generic customer text + calendar), then resolved. See
 * WarrantyService for every transition.
 */
class WarrantyClaim extends Model
{
    use LogsActivity;

    public const STATUS_OPEN = 'open';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CANCELED = 'canceled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'scheduled_exact_time' => 'boolean',
            'scheduled_window_hours' => 'integer',
            'tech_pay_cents' => 'integer',
            'tech_paid_at' => 'datetime',
            'overdue_completion_alerted_at' => 'datetime',
            'resolved_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'scheduled_at', 'technician_id', 'tech_pay_cents', 'issue', 'resolved_at', 'canceled_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
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

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technician_id');
    }

    /** The tech who did the ORIGINAL job (captured when the claim opened). */
    public function originalTechnician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'original_technician_id');
    }

    public function techPaidBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'tech_paid_by');
    }

    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'scheduled_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'resolved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    // ---- Scopes ----

    /** Still being worked: reported or with a visit on the books. */
    public function scopeLive(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_OPEN, self::STATUS_SCHEDULED]);
    }

    public function scopeNeedsScheduling(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_OPEN);
    }

    /**
     * Return visits carrying extra tech pay that is owed but not yet settled —
     * i.e. the visit is done (resolved) with a pay amount and no tech_paid_at.
     * These drive the End-of-Day warranty payout widget.
     */
    public function scopeWarrantyPayOwed(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_RESOLVED)
            ->where('tech_pay_cents', '>', 0)
            ->whereNull('tech_paid_at');
    }

    // ---- Display ----

    public function isLive(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_SCHEDULED], true);
    }

    /** Does this visit carry extra tech pay? */
    public function hasTechPay(): bool
    {
        return (int) $this->tech_pay_cents > 0;
    }

    /** Extra pay owed and not yet settled (visit complete, no tech_paid_at). */
    public function isWarrantyPayOwed(): bool
    {
        return $this->status === self::STATUS_RESOLVED
            && $this->hasTechPay()
            && $this->tech_paid_at === null;
    }

    /**
     * True when the scheduled tech differs from the tech who did the original
     * job — i.e. someone else was sent to fix it.
     */
    public function techChangedFromOriginal(): bool
    {
        return $this->original_technician_id !== null
            && $this->technician_id !== null
            && (int) $this->technician_id !== (int) $this->original_technician_id;
    }

    /**
     * "Tuesday, July 14th between 8am–10am" for the visit, '' when unscheduled.
     * Pass the customer's language when the phrase is going into their text; the
     * default English reads the dashboard/activity-log side.
     */
    public function visitPhrase(?Language $language = null): string
    {
        return TemplateRenderer::formatVisit(
            $this->scheduled_at,
            (bool) $this->scheduled_exact_time,
            $this->scheduled_window_hours,
            $language,
        );
    }

    /**
     * End of the visit's arrival window (UTC): scheduled_at + the visit's window
     * length (its override, else the configured default; 0 for exact-time).
     * Null when unscheduled. Drives the overdue-completion Telegram alert.
     */
    public function scheduledWindowEndUtc(): ?\Carbon\CarbonInterface
    {
        if ($this->scheduled_at === null) {
            return null;
        }

        $hours = $this->scheduled_exact_time
            ? 0
            : ($this->scheduled_window_hours ?: (int) config('glass.appointment_window_hours', 2));

        return $this->scheduled_at->copy()->addHours($hours);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'Needs scheduling',
            self::STATUS_SCHEDULED => 'Visit scheduled',
            self::STATUS_RESOLVED => 'Resolved',
            self::STATUS_CANCELED => 'Canceled',
            default => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'warning',
            self::STATUS_SCHEDULED => 'info',
            self::STATUS_RESOLVED => 'success',
            self::STATUS_CANCELED => 'gray',
            default => 'gray',
        };
    }
}
