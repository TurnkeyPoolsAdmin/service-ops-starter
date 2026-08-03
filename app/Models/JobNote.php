<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One logged note on a job — the job reads as a chronological conversation. The
 * job's free-text `notes` column is a different thing: the intake scratchpad.
 *
 * An author may correct or retract their OWN note, but never quietly: an edit
 * stamps `edited_at` (the original `created_at` stays), and a delete is a SOFT
 * delete so the feed can still show a tombstone at the original time.
 */
class JobNote extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'author_id');
    }

    public function wasEdited(): bool
    {
        return $this->edited_at !== null;
    }

    /** Only the author can touch their own note (super_admins included — no override). */
    public function isEditableBy(?Employee $user): bool
    {
        return $user !== null
            && $this->author_id !== null
            && $this->author_id === $user->id
            && ! $this->trashed();
    }
}
