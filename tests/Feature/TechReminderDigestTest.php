<?php

use App\Enums\JobFunction;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Warehouse;
use App\Services\JobLifecycle;
use App\Services\Messaging\TechReminderScheduler;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ])->assignRole('super_admin');

    $this->lifecycle = app(JobLifecycle::class);
    $this->entityId = Entity::where('code', 'LA')->value('id');

    $this->tech = Employee::create([
        'name' => 'Alex', 'email' => 'alex@test.local', 'phone' => '+13105550009',
        'entity_id' => $this->entityId, 'active' => true, 'can_login' => false,
        'job_functions' => [JobFunction::Technician->value],
    ]);
});

afterEach(fn () => Carbon::setTestNow());

/** Create a scheduled, tech-assigned job and force its created_at. */
function digestJob($test, string $scheduledLocal, string $createdAtLocal, array $attrs = [])
{
    $job = $test->lifecycle->createFromIntake([
        'entity_id' => $test->entityId,
        'phone_e164' => '+18185551234',
        'first_name' => 'Cust',
    ], $test->actor);

    $job->forceFill(array_merge([
        'technician_id' => $test->tech->id,
        'scheduled_at' => Carbon::parse($scheduledLocal, 'America/Los_Angeles')->utc(),
        'created_at' => Carbon::parse($createdAtLocal, 'America/Los_Angeles')->utc(),
    ], $attrs))->save();

    return $job;
}

it('night-before digest includes a job booked today for tomorrow', function () {
    // Evening of July 12; digest targets July 13.
    Carbon::setTestNow(Carbon::parse('2026-07-12 19:30', 'America/Los_Angeles'));

    // One booked days ago, one booked TODAY — both scheduled tomorrow.
    digestJob($this, '2026-07-13 15:00', '2026-07-09 10:00');
    digestJob($this, '2026-07-13 21:00', '2026-07-12 13:41'); // booked today

    $msgs = app(TechReminderScheduler::class)->run('night_before');

    expect($msgs)->toHaveCount(1);        // one digest for the one tech
    $body = $msgs[0]->rendered_body;
    expect($body)->toContain('1. 3pm')    // earlier job (window start)
        ->and($body)->toContain('2. 9pm'); // the job booked today is present
});

it('formats each job as a window + name + vehicle + address + warehouse block', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-12 19:30', 'America/Los_Angeles'));

    // Assert the English labels for clarity (crews default to Spanish → "Almacén").
    $this->tech->update(['preferred_language' => 'en']);

    $wh = Warehouse::create(['name' => 'SFV Depot', 'active' => true]);

    // 8am with a 2-hour window (not exact), full detail + a real warehouse.
    digestJob($this, '2026-07-13 08:00', '2026-07-09 10:00', [
        'customer_name_snapshot' => 'John Smith',
        'vehicle_year' => '2020', 'vehicle_make' => 'Toyota', 'vehicle_model' => 'Camry',
        'service_address' => '1234 Main St',
        'city' => 'Sylmar',
        'warehouse_id' => $wh->id,
        'scheduled_exact_time' => false,
    ]);

    // A job whose warehouse is free-text "other".
    digestJob($this, '2026-07-13 14:00', '2026-07-09 10:00', [
        'customer_name_snapshot' => 'Jane Doe',
        'warehouse_other' => 'Pep Boys Van Nuys',
        'scheduled_exact_time' => false,
    ]);

    $body = app(TechReminderScheduler::class)->run('night_before')[0]->rendered_body;

    expect($body)->toContain('1. 8am-10am')            // full window
        ->and($body)->toContain('John Smith')
        ->and($body)->toContain('2020 Toyota Camry')
        ->and($body)->toContain('1234 Main St, Sylmar')  // full address + city
        ->and($body)->toContain('Warehouse: SFV Depot')  // real warehouse name
        ->and($body)->toContain('Warehouse: Pep Boys Van Nuys'); // "other" free text
});
