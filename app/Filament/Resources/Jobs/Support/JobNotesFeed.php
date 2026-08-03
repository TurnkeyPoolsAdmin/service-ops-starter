<?php

namespace App\Filament\Resources\Jobs\Support;

use App\Models\Employee;
use App\Models\Job;
use App\Support\Timezone;
use Carbon\CarbonInterface;

/**
 * Builds the job's notes feed — one chronological list of bubbles (oldest first)
 * for `resources/views/filament/jobs/notes-feed.blade.php`.
 *
 * It merges the TWO places a note can live so the job view has a single Notes
 * section rather than two redundant ones:
 *  - the intake note (`jobs.notes`, written by the parser / intake form) — pinned
 *    first when present (many jobs have none), edited in place via the section's
 *    "Edit intake note" action since it's one field, not part of the log;
 *  - every `job_notes` row logged since, INCLUDING soft-deleted ones, which render
 *    as tombstones at their original time so the history has no silent gaps.
 */
class JobNotesFeed
{
    /**
     * @return array<int, array{
     *     id: ?int, author: string, initials: string, at: string, edited_at: ?string,
     *     body: ?string, is_intake: bool, is_deleted: bool, can_manage: bool
     * }>
     */
    public static function bubbles(Job $job, ?Employee $viewer = null): array
    {
        $viewer ??= auth()->user();
        $bubbles = [];

        if (filled($job->notes)) {
            $bubbles[] = [
                'id' => null,
                'author' => 'Intake note',
                'initials' => 'IN',
                'at' => self::stamp($job->created_at),
                'edited_at' => null,
                'body' => $job->notes,
                'is_intake' => true,
                'is_deleted' => false,
                'can_manage' => false,   // edited via the section's "Edit intake note" action
            ];
        }

        foreach ($job->noteEntriesWithDeleted()->with('author')->get() as $note) {
            $author = $note->author?->name ?? 'System';

            $bubbles[] = [
                'id' => $note->id,
                'author' => $author,
                'initials' => self::initials($author),
                'at' => self::stamp($note->created_at),
                'edited_at' => $note->wasEdited() ? self::stamp($note->edited_at) : null,
                'body' => $note->trashed() ? null : $note->body,
                'is_intake' => false,
                'is_deleted' => (bool) $note->trashed(),
                'can_manage' => $note->isEditableBy($viewer instanceof Employee ? $viewer : null),
            ];
        }

        return $bubbles;
    }

    private static function stamp(?CarbonInterface $at): string
    {
        return $at ? Timezone::toDisplay($at)->format('M j, Y g:i A') : '';
    }

    /** "Jose Ramirez" -> "JR" (first + last initial). */
    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $letters = array_map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_filter($words));

        return implode('', array_slice($letters, 0, 2)) ?: '?';
    }
}
