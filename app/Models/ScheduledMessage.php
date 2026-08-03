<?php

namespace App\Models;

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\TemplateKey;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'template_key' => TemplateKey::class,
            'message_type' => MessageType::class,
            'status' => MessageStatus::class,
            'attempt_count' => 'integer',
            'provider_response' => 'array',
            'scheduled_for' => 'datetime',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'canceled_at' => 'datetime',
            'delivered_at' => 'datetime',
            'delivery_failed_at' => 'datetime',
            'delivery_unconfirmed_alerted_at' => 'datetime',
        ];
    }

    /**
     * Deterministic idempotency key guaranteeing one row per lifecycle event.
     * One-offs (welcome, time confirmation) omit the timestamp; recurring sends
     * (reminders) include the UTC scheduled time so a reschedule produces a
     * distinct key.
     */
    public static function makeIdempotencyKey(
        ?int $jobId,
        TemplateKey|string $templateKey,
        ?CarbonInterface $scheduledForUtc = null,
    ): string {
        $key = $templateKey instanceof TemplateKey ? $templateKey->value : $templateKey;
        $base = "job:{$jobId}:tpl:{$key}";

        return $scheduledForUtc
            ? $base.':for:'.$scheduledForUtc->copy()->utc()->format('Y-m-d\TH:i:s\Z')
            : $base;
    }

    // ---- Scopes ----

    /** Queued rows whose send time has arrived (or is immediate). */
    public function scopeDue(Builder $q, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $q->where('status', MessageStatus::Queued->value)
            ->where(function (Builder $w) use ($now) {
                $w->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', $now);
            });
    }

    /** Rows not yet sent/finalized — eligible for cancellation. */
    public function scopeUnsent(Builder $q): Builder
    {
        return $q->whereIn('status', array_map(fn ($c) => $c->value, MessageStatus::unsentCases()));
    }

    public function isUnsent(): bool
    {
        return in_array($this->status, MessageStatus::unsentCases(), true);
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
