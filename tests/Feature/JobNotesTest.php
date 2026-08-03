<?php

use App\Filament\Resources\Jobs\Support\JobNotesFeed;
use App\Filament\Resources\Jobs\Support\JobTimeline;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\JobNote;
use App\Services\JobLifecycle;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WarehouseSeeder;

beforeEach(function () {
    $this->seed([
        RoleSeeder::class,
        EntitySeeder::class,
        WarehouseSeeder::class,
        MessageTemplateSeeder::class,
        SettingSeeder::class,
    ]);

    $this->actor = Employee::create([
        'name' => 'Nora Notes', 'email' => 'nora@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->lifecycle = app(JobLifecycle::class);
});

function notesJob($test, ?string $intakeNote = null): Job
{
    return $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185559911',
        'first_name' => 'Nina',
        'last_name' => 'Note',
        'vehicle_year' => '2019', 'vehicle_make' => 'Honda', 'vehicle_model' => 'Civic',
        'glass_position' => 'Windshield',
        'quote_amount_cents' => 40000,
        'requested_daytime_raw' => 'Tomorrow',
        'notes' => $intakeNote,
        'intake_raw_text' => 'raw', 'parsed_payload_json' => ['x' => 1],
        'parse_status' => 'clean',
    ], $test->actor);
}

function otherEmployee(): Employee
{
    return Employee::create([
        'name' => 'Sam Second', 'email' => 'sam-notes@test.local', 'can_login' => true,
        'active' => true, 'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
}

/** The blade the Notes section renders, exercised the way Filament calls it. */
function renderNotesFeed(Job $job): string
{
    return view('filament.jobs.notes-feed', ['getRecord' => fn () => $job])->render();
}

it('logs notes chronologically with their author', function () {
    $job = notesJob($this);

    $first = $job->noteEntries()->create([
        'author_id' => $this->actor->id,
        'body' => 'Called customer, no answer - left voicemail.',
    ]);
    $second = $job->noteEntries()->create([
        'author_id' => $this->actor->id,
        'body' => 'Customer called back, wants Thursday morning.',
    ]);
    // Make the ordering unambiguous even within the same second.
    $first->update(['created_at' => now()->subHour()]);

    $bodies = $job->refresh()->noteEntries->pluck('body')->all();

    expect($bodies)->toBe([$first->body, $second->body])
        ->and($job->noteEntries->first()->author->name)->toBe('Nora Notes');
});

it('renders logged notes as bubbles with author and time', function () {
    $job = notesJob($this);
    $job->noteEntries()->create(['author_id' => $this->actor->id, 'body' => 'Part is on backorder.']);

    $html = renderNotesFeed($job);

    expect($html)->toContain('Part is on backorder.')
        ->and($html)->toContain('Nora Notes')
        ->and($html)->toContain('NN');   // initials avatar
});

it('shows an empty state when a job has no notes at all', function () {
    $job = notesJob($this);

    expect(JobNotesFeed::bubbles($job, $this->actor))->toBe([])
        ->and(renderNotesFeed($job))->toContain('No notes yet.');
});

it('omits the intake bubble entirely when the job has no intake note', function () {
    $job = notesJob($this);
    $job->noteEntries()->create(['author_id' => $this->actor->id, 'body' => 'Only a logged note.']);

    $bubbles = JobNotesFeed::bubbles($job, $this->actor);

    expect($bubbles)->toHaveCount(1)
        ->and($bubbles[0]['is_intake'])->toBeFalse()
        ->and(renderNotesFeed($job))->not->toContain('Intake note');
});

it('pins the intake note as the first bubble, ahead of logged notes', function () {
    $job = notesJob($this, 'Gate code 4321.');
    $job->noteEntries()->create(['author_id' => $this->actor->id, 'body' => 'Confirmed the gate code.']);

    $bubbles = JobNotesFeed::bubbles($job, $this->actor);
    $html = renderNotesFeed($job);

    expect($bubbles[0]['is_intake'])->toBeTrue()
        ->and($bubbles[0]['body'])->toBe('Gate code 4321.')
        ->and($bubbles[0]['can_manage'])->toBeFalse()   // edited via "Edit intake note"
        ->and(strpos($html, 'Gate code 4321.'))->toBeLessThan(strpos($html, 'Confirmed the gate code.'));
});

it('escapes note bodies so they cannot inject markup', function () {
    $job = notesJob($this);
    $job->noteEntries()->create(['author_id' => $this->actor->id, 'body' => '<script>alert(1)</script>']);

    expect(renderNotesFeed($job))
        ->not->toContain('<script>')
        ->toContain('&lt;script&gt;');
});

it('marks an edited note as edited, keeping the original timestamp', function () {
    $job = notesJob($this);
    $note = $job->noteEntries()->create(['author_id' => $this->actor->id, 'body' => 'Tech ETA 10am.']);
    $originalCreatedAt = $note->created_at;

    $note->update(['body' => 'Tech ETA 11am.', 'edited_at' => now()->addMinutes(5)]);

    $bubble = JobNotesFeed::bubbles($job->refresh(), $this->actor)[0];

    expect($note->refresh()->created_at->eq($originalCreatedAt))->toBeTrue()
        ->and($bubble['edited_at'])->not->toBeNull()
        ->and($bubble['at'])->not->toBe($bubble['edited_at'])
        ->and(renderNotesFeed($job))->toContain('edited');
});

it('leaves a tombstone at the original time when a note is deleted', function () {
    $job = notesJob($this);
    $note = $job->noteEntries()->create(['author_id' => $this->actor->id, 'body' => 'Wrong job - ignore.']);
    $stamp = JobNotesFeed::bubbles($job, $this->actor)[0]['at'];

    $note->delete();

    $bubble = JobNotesFeed::bubbles($job->refresh(), $this->actor)[0];
    $html = renderNotesFeed($job);

    expect($bubble['is_deleted'])->toBeTrue()
        ->and($bubble['body'])->toBeNull()
        ->and($bubble['at'])->toBe($stamp)          // original timestamp preserved
        ->and($bubble['can_manage'])->toBeFalse()   // nothing left to edit
        ->and($html)->toContain('Note deleted.')
        ->and($html)->not->toContain('Wrong job - ignore.');
});

it('only lets the author manage their own note', function () {
    $job = notesJob($this);
    $mine = $job->noteEntries()->create(['author_id' => $this->actor->id, 'body' => 'Mine.']);
    $theirs = $job->noteEntries()->create(['author_id' => otherEmployee()->id, 'body' => 'Theirs.']);

    expect($mine->isEditableBy($this->actor))->toBeTrue()
        ->and($theirs->isEditableBy($this->actor))->toBeFalse()          // even as super_admin
        ->and(collect(JobNotesFeed::bubbles($job, $this->actor))->pluck('can_manage')->all())
        ->toBe([true, false]);
});

it('never offers management of a deleted note', function () {
    $job = notesJob($this);
    $note = $job->noteEntries()->create(['author_id' => $this->actor->id, 'body' => 'Gone.']);
    $note->delete();

    expect($note->refresh()->isEditableBy($this->actor))->toBeFalse();
});

it('includes logged notes in the job timeline and flags edited ones', function () {
    $job = notesJob($this);
    $job->noteEntries()->create(['author_id' => $this->actor->id, 'body' => 'Tech running late.']);
    $job->noteEntries()->create([
        'author_id' => $this->actor->id, 'body' => 'Customer notified.', 'edited_at' => now(),
    ]);

    $timeline = (string) JobTimeline::html($job);

    expect($timeline)->toContain('Note: Tech running late.')
        ->and($timeline)->toContain('Note: Customer notified. (edited)');
});

it('renders the notes feed on the job page, wired to the author-only actions', function () {
    $job = notesJob($this);
    $mine = $job->noteEntries()->create(['author_id' => $this->actor->id, 'body' => 'Visible on the page.']);
    $theirs = $job->noteEntries()->create(['author_id' => otherEmployee()->id, 'body' => 'Someone elses note.']);

    $response = $this->actingAs($this->actor)
        ->get(\App\Filament\Resources\Jobs\JobResource::getUrl('view', ['record' => $job]));

    $response->assertOk()
        ->assertSee('Visible on the page.')
        ->assertSee('Someone elses note.')
        // Only my own note gets Edit/Delete triggers.
        ->assertSee("mountAction('editJobNote', { note: {$mine->id} })", escape: false)
        ->assertSee("mountAction('deleteJobNote', { note: {$mine->id} })", escape: false)
        // ...and someone else's note gets none.
        ->assertDontSee("mountAction('editJobNote', { note: {$theirs->id} })", escape: false)
        ->assertDontSee("mountAction('deleteJobNote', { note: {$theirs->id} })", escape: false);
});

it('keeps notes when a job is soft-deleted', function () {
    $job = notesJob($this);
    $job->noteEntries()->create(['author_id' => $this->actor->id, 'body' => 'Survives a soft delete.']);

    $job->delete();

    expect(JobNote::where('job_id', $job->id)->count())->toBe(1);
});
