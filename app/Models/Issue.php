<?php

namespace App\Models;

use App\Enums\IssueType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * "Anything the dispatch team does that feels off" — deleted jobs, cancelled
 * jobs, and bank transactions matched to a tech payout for the wrong amount.
 * One OPEN issue per (type, subject) at a time: raise() is idempotent so a
 * repeated trigger (e.g. re-cancelling, re-suggesting the same mismatch)
 * never creates a duplicate row. clearOpenFor() is the "never mind, this
 * resolved itself" path (unmatch, a clean re-match, un-cancelling a job) —
 * it removes the OPEN issue outright, distinct from clear(), which is the
 * super admin's explicit "reviewed and done" action that keeps the audit trail.
 */
class Issue extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => IssueType::class,
            'details' => 'array',
            'cleared_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'raised_by');
    }

    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cleared_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /**
     * Raise an issue, or return the already-OPEN one for this exact
     * type+subject so repeated triggers never duplicate a row.
     */
    public static function raise(IssueType $type, ?Model $subject, array $details = [], ?int $raisedBy = null): self
    {
        $existing = static::query()
            ->open()
            ->where('type', $type->value)
            ->when(
                $subject,
                fn (Builder $q) => $q->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey()),
                fn (Builder $q) => $q->whereNull('subject_type')->whereNull('subject_id'),
            )
            ->first();

        if ($existing) {
            return $existing;
        }

        return static::create([
            'type' => $type,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'details' => $details,
            'raised_by' => $raisedBy,
        ]);
    }

    /** Delete any OPEN issue of this type for this subject — the situation resolved itself. */
    public static function clearOpenFor(Model $subject, IssueType $type): void
    {
        static::query()
            ->open()
            ->where('type', $type->value)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->delete();
    }

    /** A super admin has reviewed this issue and it's done — keeps the audit trail. */
    public function clear(?int $by, ?string $note): void
    {
        $this->update([
            'status' => 'cleared',
            'cleared_by' => $by,
            'cleared_at' => now(),
            'resolution_note' => $note,
        ]);
    }
}
