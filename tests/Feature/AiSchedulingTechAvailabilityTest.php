<?php

use App\Enums\JobStatus;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\WarrantyClaim;
use App\Services\AiScheduling\SchedulingConstraint;
use App\Services\AiScheduling\TechAvailability;
use App\Services\AiScheduling\TechDayLoad;
use App\Support\Timezone;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);
    Carbon::setTestNow(Carbon::parse('2026-07-20 09:00', 'America/Los_Angeles'));
    $this->availability = new TechAvailability;

    $this->tech = Employee::create([
        'name' => 'Jose',
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'job_functions' => ['technician'],
        'active' => true,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

/** An open, uncompleted job scheduled for the tech at a given Pacific 'H:i' on the given local date. */
function aiSchedulingJobFor(Employee $tech, string $localDate, string $localTime, bool $exact = true): Job
{
    $customer = Customer::create(['phone_e164' => '+1818555'.random_int(1000, 9999)]);
    $scheduledUtc = Carbon::parse("{$localDate} {$localTime}", Timezone::display())->utc();

    return Job::create([
        'job_number' => 'LA-2026-'.random_int(10000, 99999),
        'entity_id' => $tech->entity_id,
        'customer_id' => $customer->id,
        'technician_id' => $tech->id,
        'status' => JobStatus::Scheduled->value,
        'scheduled_at' => $scheduledUtc,
        'scheduled_exact_time' => $exact,
    ]);
}

// ---- TechDayLoad::bucket / noteFor ----

it('reads a tech with no jobs that part of day as open', function () {
    $load = $this->availability->loadFor($this->tech, '2026-07-22');
    $constraint = SchedulingConstraint::window('2026-07-22', 'morning');

    expect($load->bucket($constraint))->toBe(TechDayLoad::OPEN)
        ->and($load->noteFor($constraint, null))->toBe('');
});

it('one job that morning is light, not busy', function () {
    aiSchedulingJobFor($this->tech, '2026-07-22', '09:00');

    $load = $this->availability->loadFor($this->tech, '2026-07-22');
    $constraint = SchedulingConstraint::window('2026-07-22', 'morning');

    expect($load->morning)->toBe(1)
        ->and($load->bucket($constraint))->toBe(TechDayLoad::LIGHT)
        ->and($load->noteFor($constraint, null))->toContain('one job that morning');
});

it('two jobs that morning is busy', function () {
    aiSchedulingJobFor($this->tech, '2026-07-22', '08:00');
    aiSchedulingJobFor($this->tech, '2026-07-22', '10:00');

    $load = $this->availability->loadFor($this->tech, '2026-07-22');
    $constraint = SchedulingConstraint::window('2026-07-22', 'morning');

    expect($load->morning)->toBe(2)
        ->and($load->bucket($constraint))->toBe(TechDayLoad::BUSY)
        ->and($load->noteFor($constraint, null))->toContain('2 jobs that morning');
});

it('a job in a different part of day does not count against the requested part', function () {
    aiSchedulingJobFor($this->tech, '2026-07-22', '15:00'); // afternoon

    $load = $this->availability->loadFor($this->tech, '2026-07-22');
    $constraint = SchedulingConstraint::window('2026-07-22', 'morning');

    expect($load->morning)->toBe(0)
        ->and($load->bucket($constraint))->toBe(TechDayLoad::OPEN);
});

it('a scheduled warranty visit counts as load like a job', function () {
    $originalJob = aiSchedulingJobFor($this->tech, '2026-07-10', '09:00'); // unrelated original job

    WarrantyClaim::create([
        'job_id' => $originalJob->id,
        'entity_id' => $originalJob->entity_id,
        'customer_id' => $originalJob->customer_id,
        'technician_id' => $this->tech->id,
        'status' => WarrantyClaim::STATUS_SCHEDULED,
        'issue' => 'Whistling noise',
        'scheduled_at' => Carbon::parse('2026-07-22 09:00', Timezone::display())->utc(),
    ]);

    $load = $this->availability->loadFor($this->tech, '2026-07-22');

    expect($load->morning)->toBe(1);
});

// ---- TechAvailability::hasHardConflict ----

it('has no hard conflict for a window/flexible constraint no matter what is booked', function () {
    aiSchedulingJobFor($this->tech, '2026-07-22', '09:00');
    $constraint = SchedulingConstraint::window('2026-07-22', 'morning');

    expect($this->availability->hasHardConflict($this->tech, $constraint))->toBeFalse();
});

it('flags a hard conflict when an exact request overlaps an existing exact-time job window', function () {
    aiSchedulingJobFor($this->tech, '2026-07-22', '09:00', exact: true); // window 9-11am (2h default)
    $constraint = SchedulingConstraint::exact('2026-07-22', '10:00');

    expect($this->availability->hasHardConflict($this->tech, $constraint))->toBeTrue();
});

it('does not flag a conflict when the exact-time jobs do not actually overlap', function () {
    aiSchedulingJobFor($this->tech, '2026-07-22', '08:00', exact: true); // window 8-10am
    $constraint = SchedulingConstraint::exact('2026-07-22', '13:00');

    expect($this->availability->hasHardConflict($this->tech, $constraint))->toBeFalse();
});

it('does not flag a conflict against a non-exact (window) existing job', function () {
    aiSchedulingJobFor($this->tech, '2026-07-22', '10:00', exact: false);
    $constraint = SchedulingConstraint::exact('2026-07-22', '10:00');

    expect($this->availability->hasHardConflict($this->tech, $constraint))->toBeFalse();
});
