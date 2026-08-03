<?php

use App\Enums\IssueType;
use App\Enums\Language;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Issue;
use App\Models\Job;
use App\Models\MessageTemplate;
use App\Services\JobLifecycle;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    Carbon::setTestNow(Carbon::parse('2026-07-20 12:00', 'America/Los_Angeles'));

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->tech = Employee::create([
        'name' => 'Tech', 'email' => 'tech@test.local', 'active' => true,
        'job_functions' => ['technician'], 'phone' => '+18185550000',
    ]);

    $this->lifecycle = app(JobLifecycle::class);
});

afterEach(fn () => Carbon::setTestNow());

function sweepJob($test, array $overrides = []): Job
{
    return $test->lifecycle->createFromIntake(array_merge([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+1818555'.random_int(1000, 9999),
        'first_name' => 'Alan',
        'quote_amount_cents' => 45000,
        'parts_json' => [['label' => 'FW05046 windshield', 'cost_cents' => 10000]],
    ], $overrides), $test->actor);
}

// ---- Completed but unpaid the next day ----

it('raises a job_completed_unpaid issue for a job finished a prior day still unpaid', function () {
    $job = sweepJob($this);
    // Finished yesterday, nothing collected.
    $job->forceFill([
        'work_completed_at' => Carbon::parse('2026-07-19 15:00', 'America/Los_Angeles'),
    ])->save();

    $this->artisan('issues:sweep')->assertSuccessful();

    $issue = Issue::open()->where('type', IssueType::JobCompletedUnpaid->value)
        ->where('subject_id', $job->id)->first();

    expect($issue)->not->toBeNull()
        ->and($issue->details['job_number'])->toBe($job->job_number)
        ->and($issue->details['balance_due_cents'])->toBe(45000);
});

it('does NOT raise for a job completed earlier the SAME day (not yet the next day)', function () {
    $job = sweepJob($this);
    $job->forceFill([
        'work_completed_at' => Carbon::parse('2026-07-20 09:00', 'America/Los_Angeles'),
    ])->save();

    $this->artisan('issues:sweep')->assertSuccessful();

    expect(Issue::open()->where('type', IssueType::JobCompletedUnpaid->value)->where('subject_id', $job->id)->exists())
        ->toBeFalse();
});

it('clears the completed_unpaid issue once the job is paid in full', function () {
    $job = sweepJob($this);
    $job->forceFill(['work_completed_at' => Carbon::parse('2026-07-19 15:00', 'America/Los_Angeles')])->save();

    $this->artisan('issues:sweep');
    expect(Issue::open()->where('type', IssueType::JobCompletedUnpaid->value)->where('subject_id', $job->id)->exists())->toBeTrue();

    // Pay it off, then re-sweep — the issue heals itself.
    $job->forceFill([
        'amount_collected_cents' => 45000,
        'payment_received_at' => now(),
    ])->save();

    $this->artisan('issues:sweep');
    expect(Issue::open()->where('type', IssueType::JobCompletedUnpaid->value)->where('subject_id', $job->id)->exists())->toBeFalse();
});

it('does not duplicate the completed_unpaid issue across repeated sweeps', function () {
    $job = sweepJob($this);
    $job->forceFill(['work_completed_at' => Carbon::parse('2026-07-19 15:00', 'America/Los_Angeles')])->save();

    $this->artisan('issues:sweep');
    $this->artisan('issues:sweep');

    expect(Issue::where('type', IssueType::JobCompletedUnpaid->value)->where('subject_id', $job->id)->count())->toBe(1);
});

// ---- Part not ordered for a job scheduled today ----

it('raises a part_not_ordered issue for a job scheduled today with no part ordered', function () {
    $job = sweepJob($this);
    $this->lifecycle->schedule(
        $job,
        Carbon::parse('2026-07-20 14:00', 'America/Los_Angeles'),
        $this->actor,
        technicianId: $this->tech->id,
    );

    $this->artisan('issues:sweep')->assertSuccessful();

    $issue = Issue::open()->where('type', IssueType::PartNotOrdered->value)
        ->where('subject_id', $job->id)->first();

    expect($issue)->not->toBeNull()
        ->and($issue->details['job_number'])->toBe($job->job_number);
});

it('does NOT raise part_not_ordered for a job scheduled tomorrow', function () {
    $job = sweepJob($this);
    $this->lifecycle->schedule(
        $job,
        Carbon::parse('2026-07-21 14:00', 'America/Los_Angeles'),
        $this->actor,
        technicianId: $this->tech->id,
    );

    $this->artisan('issues:sweep')->assertSuccessful();

    expect(Issue::open()->where('type', IssueType::PartNotOrdered->value)->where('subject_id', $job->id)->exists())
        ->toBeFalse();
});

it('clears the part_not_ordered issue once the part is ordered', function () {
    $job = sweepJob($this);
    $this->lifecycle->schedule(
        $job,
        Carbon::parse('2026-07-20 14:00', 'America/Los_Angeles'),
        $this->actor,
        technicianId: $this->tech->id,
    );

    $this->artisan('issues:sweep');
    expect(Issue::open()->where('type', IssueType::PartNotOrdered->value)->where('subject_id', $job->id)->exists())->toBeTrue();

    $this->lifecycle->markPartsOrdered($job->refresh(), $this->actor);

    $this->artisan('issues:sweep');
    expect(Issue::open()->where('type', IssueType::PartNotOrdered->value)->where('subject_id', $job->id)->exists())->toBeFalse();
});

// ---- Spanish translation gone stale ----

/**
 * The 2026_07_22_120000 cutover: every Spanish row counts as reviewed as of now,
 * so seeded/migration-written English timestamps don't read as real edits. The
 * beforeEach seeder re-inserts rows after that migration ran, so tests re-apply it.
 */
function baselineTemplates(string $at): void
{
    Carbon::setTestNow(Carbon::parse($at, 'America/Los_Angeles'));
    // Both languages, not just Spanish: beforeEach seeds BEFORE freezing the
    // clock, so the seeded rows carry real wall-clock timestamps that would sit
    // ahead of every dated edit below. Level them, then move time forward.
    MessageTemplate::query()->update(['updated_at' => now()]);
}

/** Edit a template body "later", so updated_at actually moves off the seeded value. */
function editTemplate(string $key, Language $language, string $body, string $at): MessageTemplate
{
    Carbon::setTestNow(Carbon::parse($at, 'America/Los_Angeles'));

    $row = MessageTemplate::query()->forKey($key)->where('language', $language->value)->firstOrFail();
    $row->update(['body' => $body]);

    return $row->refresh();
}

it('raises a translation_stale issue when the English body is edited after the Spanish', function () {
    baselineTemplates('2026-07-20 12:30');
    $spanish = editTemplate('job_complete', Language::Spanish, 'Cuerpo en espanol.', '2026-07-20 13:00');
    editTemplate('job_complete', Language::English, 'Freshly reworded English body.', '2026-07-20 14:00');

    $this->artisan('issues:sweep')->assertSuccessful();

    $issue = Issue::open()->where('type', IssueType::TranslationStale->value)->first();

    expect($issue)->not->toBeNull()
        ->and($issue->subject_type)->toBe(MessageTemplate::class)
        ->and($issue->subject_id)->toBe($spanish->id)
        ->and($issue->details['english_body'])->toBe('Freshly reworded English body.')
        ->and($issue->details['spanish_body'])->toBe('Cuerpo en espanol.');
});

it('does NOT raise translation_stale when the Spanish is newer than the English', function () {
    baselineTemplates('2026-07-20 12:30');
    editTemplate('job_complete', Language::English, 'English first.', '2026-07-20 13:00');
    editTemplate('job_complete', Language::Spanish, 'Espanol despues.', '2026-07-20 14:00');

    $this->artisan('issues:sweep')->assertSuccessful();

    expect(Issue::open()->where('type', IssueType::TranslationStale->value)->exists())->toBeFalse();
});

it('raises only ONE issue no matter how many times the English is edited', function () {
    baselineTemplates('2026-07-20 12:30');
    editTemplate('job_complete', Language::Spanish, 'Cuerpo en espanol.', '2026-07-20 13:00');

    editTemplate('job_complete', Language::English, 'Edit one.', '2026-07-20 14:00');
    $this->artisan('issues:sweep');

    editTemplate('job_complete', Language::English, 'Edit two.', '2026-07-20 15:00');
    $this->artisan('issues:sweep');

    expect(Issue::open()->where('type', IssueType::TranslationStale->value)->count())->toBe(1);
});

it('clears the translation_stale issue once the Spanish is saved', function () {
    baselineTemplates('2026-07-20 12:30');
    editTemplate('job_complete', Language::Spanish, 'Cuerpo en espanol.', '2026-07-20 13:00');
    editTemplate('job_complete', Language::English, 'Reworded.', '2026-07-20 14:00');

    $this->artisan('issues:sweep');
    expect(Issue::open()->where('type', IssueType::TranslationStale->value)->exists())->toBeTrue();

    editTemplate('job_complete', Language::Spanish, 'Cuerpo actualizado.', '2026-07-20 15:00');

    $this->artisan('issues:sweep');
    expect(Issue::open()->where('type', IssueType::TranslationStale->value)->exists())->toBeFalse();
});

it('clears the issue when the Spanish is confirmed still accurate without an edit', function () {
    baselineTemplates('2026-07-20 12:30');
    $spanish = editTemplate('job_complete', Language::Spanish, 'Cuerpo en espanol.', '2026-07-20 13:00');
    editTemplate('job_complete', Language::English, 'Typo fix only.', '2026-07-20 14:00');

    $this->artisan('issues:sweep');
    expect(Issue::open()->where('type', IssueType::TranslationStale->value)->exists())->toBeTrue();

    // What the "Spanish still fine" action does — no body change, just reviewed.
    Carbon::setTestNow(Carbon::parse('2026-07-20 15:00', 'America/Los_Angeles'));
    $spanish->markTranslationReviewed();

    $this->artisan('issues:sweep');
    expect(Issue::open()->where('type', IssueType::TranslationStale->value)->exists())->toBeFalse()
        ->and($spanish->refresh()->body)->toBe('Cuerpo en espanol.');
});
