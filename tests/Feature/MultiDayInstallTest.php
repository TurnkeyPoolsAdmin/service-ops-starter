<?php

use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Enums\TemplateKey;
use App\Filament\Resources\Jobs\Pages\ViewJob;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\ScheduledMessage;
use App\Services\JobLifecycle;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->tech = Employee::create([
        'name' => 'Tech', 'email' => 'tech@test.local', 'active' => true,
        'job_functions' => ['technician'], 'phone' => '+18185550000',
    ]);

    $this->actingAs($this->actor);
    $this->lifecycle = app(JobLifecycle::class);
});

/** A multi-day job scheduled for its first day. */
function multiDayJob($test): Job
{
    $job = $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Alan',
        'quote_amount_cents' => 45000,
        'is_multi_day' => true,
    ], $test->actor);

    $test->lifecycle->schedule(
        $job,
        Carbon::parse('2026-07-08 09:00', 'America/Los_Angeles'),
        $test->actor,
        technicianId: $test->tech->id,
    );

    return $job->refresh();
}

function multiDayBodies(Job $job, TemplateKey $key): array
{
    return ScheduledMessage::where('job_id', $job->id)
        ->where('template_key', $key->value)
        ->pluck('rendered_body')->all();
}

it('tags the job multi-day from intake and reports it is on day one', function () {
    $job = multiDayJob($this);

    expect($job->is_multi_day)->toBeTrue()
        ->and($job->needsSecondVisitScheduled())->toBeTrue()
        ->and($job->isAwaitingSecondVisit())->toBeFalse();
});

it('sends the first day back to needs scheduling without completing it', function () {
    $job = multiDayJob($this);

    $this->lifecycle->completeFirstInstallDay($job, $this->actor);
    $job->refresh();

    expect($job->status)->toBe(JobStatus::PendingSchedule)
        ->and($job->scheduled_at)->toBeNull()
        ->and($job->work_completed_at)->toBeNull()
        ->and($job->multi_day_first_completed_at)->not->toBeNull()
        ->and($job->isAwaitingSecondVisit())->toBeTrue();

    // No completion text — the job isn't finished.
    expect(multiDayBodies($job, TemplateKey::JobComplete))->toBeEmpty();
});

it('texts the next-visit note (not another time confirmation) when the second visit is scheduled', function () {
    $job = multiDayJob($this);
    $this->lifecycle->completeFirstInstallDay($job, $this->actor);

    $this->lifecycle->schedule(
        $job->refresh(),
        Carbon::parse('2026-07-10 09:00', 'America/Los_Angeles'),
        $this->actor,
        technicianId: $this->tech->id,
    );

    $bodies = multiDayBodies($job, TemplateKey::MultiDayNextVisit);
    expect($bodies)->toHaveCount(1)
        ->and($bodies[0])->toContain('next part of your service')
        ->and($bodies[0])->toContain('July 10');

    // The time confirmation went out once, for day one only.
    expect(multiDayBodies($job, TemplateKey::TimeConfirmation))->toHaveCount(1);
    expect($job->refresh()->status)->toBe(JobStatus::Scheduled);
});

it('runs the normal completion flow on the final visit', function () {
    $job = multiDayJob($this);
    $this->lifecycle->completeFirstInstallDay($job, $this->actor);
    $this->lifecycle->schedule(
        $job->refresh(),
        Carbon::parse('2026-07-10 09:00', 'America/Los_Angeles'),
        $this->actor,
        technicianId: $this->tech->id,
    );

    $job->refresh();
    expect($job->needsSecondVisitScheduled())->toBeFalse();

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('markWorkComplete', ['payment_preference' => PaymentMethod::Zelle->value]);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::WorkCompleted)
        ->and($job->work_completed_at)->not->toBeNull()
        ->and(multiDayBodies($job, TemplateKey::JobComplete))->toHaveCount(1);
});

it('offers the day-1 action instead of mark work complete, and schedules the second visit inline', function () {
    $job = multiDayJob($this);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->assertActionVisible('completeFirstInstallDay')
        ->assertActionHidden('markWorkComplete')
        ->callAction('completeFirstInstallDay', [
            'next_visit_known' => 'yes',
            'scheduled_date' => '2026-07-10',
            'scheduled_time' => '09:00',
            'time_phrasing' => 'window',
            'technician_id' => $this->tech->id,
        ]);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Scheduled)
        ->and($job->work_completed_at)->toBeNull()
        ->and(multiDayBodies($job, TemplateKey::MultiDayNextVisit))->toHaveCount(1);

    // Second visit booked → the real completion action is back.
    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->assertActionVisible('markWorkComplete')
        ->assertActionHidden('completeFirstInstallDay');
});

it('leaves the job unscheduled when there is no date for the second visit yet', function () {
    $job = multiDayJob($this);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('completeFirstInstallDay', ['next_visit_known' => 'no']);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::PendingSchedule)
        ->and($job->scheduled_at)->toBeNull()
        ->and(multiDayBodies($job, TemplateKey::MultiDayNextVisit))->toBeEmpty();

    // The text goes out later, when the date finally arrives.
    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->assertActionVisible('schedule');
});

it('cancels day one reminders when the first day is completed', function () {
    $job = multiDayJob($this);
    // Reminders only exist for an appointment still in the future.
    $this->lifecycle->schedule(
        $job, Carbon::now('America/Los_Angeles')->addDays(3)->setTime(9, 0), $this->actor,
        technicianId: $this->tech->id,
    );
    $job->refresh();

    expect(ScheduledMessage::where('job_id', $job->id)->where('message_type', 'reminder')
        ->where('status', 'queued')->count())->toBeGreaterThan(0);

    $this->lifecycle->completeFirstInstallDay($job, $this->actor);

    expect(ScheduledMessage::where('job_id', $job->id)->where('message_type', 'reminder')
        ->where('status', 'queued')->count())->toBe(0);
});

it('leaves a normal job on the standard completion path', function () {
    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185559999',
        'first_name' => 'Bea',
        'quote_amount_cents' => 30000,
    ], $this->actor);
    $this->lifecycle->schedule(
        $job, Carbon::parse('2026-07-08 09:00', 'America/Los_Angeles'), $this->actor,
        technicianId: $this->tech->id,
    );

    Livewire::test(ViewJob::class, ['record' => $job->refresh()->getRouteKey()])
        ->assertActionHidden('completeFirstInstallDay')
        ->assertActionVisible('markWorkComplete');
});
