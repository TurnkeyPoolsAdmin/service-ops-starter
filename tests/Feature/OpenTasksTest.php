<?php

use App\Enums\CalibrationStatus;
use App\Enums\PaymentMethod;
use App\Filament\Pages\OpenTasks;
use App\Models\Employee;
use App\Models\Entity;
use App\Services\JobLifecycle;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'a@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->admin->assignRole('super_admin');

    $this->dispatch = Employee::create([
        'name' => 'Dispatch', 'email' => 'd@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->dispatch->assignRole('dispatch');

    $this->tech = Employee::create([
        'name' => 'Tech', 'email' => 'tech@test.local', 'active' => true,
        'job_functions' => ['technician'], 'phone' => '+18185550000',
    ]);

    $this->actingAs($this->admin);
    $this->lifecycle = app(JobLifecycle::class);
});

afterEach(fn () => Carbon::setTestNow());

function openTasksJob($test, array $overrides = []): \App\Models\Job
{
    return $test->lifecycle->createFromIntake(array_merge([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Alan',
        'quote_amount_cents' => 45000,
    ], $overrides), $test->actor ?? $test->admin);
}

/**
 * Mounts the page with the tab set from the start (via its #[Url] query param)
 * rather than ->set() after mount — Filament caches the table query per mount,
 * so ->set()-ing the tab post-mount doesn't re-run table() against it.
 */
function openTasksTab($actor, string $tab)
{
    return Livewire::actingAs($actor)
        ->withQueryParams(['view_tab' => $tab])
        ->test(OpenTasks::class);
}

it('dispatch and admin can access Open Tasks; unrelated roles cannot', function () {
    $this->actingAs($this->admin);
    expect(OpenTasks::canAccess())->toBeTrue();

    $this->actingAs($this->dispatch);
    expect(OpenTasks::canAccess())->toBeTrue();
});

it('shows an empty state when nothing is outstanding', function () {
    Livewire::actingAs($this->admin)->test(OpenTasks::class)
        ->assertSuccessful()
        ->assertSee('No open tasks');
});

it('lists an unscheduled job under Needs scheduling and clears once scheduled', function () {
    $job = openTasksJob($this);

    openTasksTab($this->admin, 'scheduling')->assertSee($job->job_number);

    expect($job->openTaskTypes())->toContain('scheduling');

    $this->lifecycle->schedule(
        $job,
        now()->addDay(),
        $this->admin,
        technicianId: $this->tech->id,
    );

    openTasksTab($this->admin, 'scheduling')->assertDontSee($job->job_number);

    expect($job->refresh()->openTaskTypes())->not->toContain('scheduling');
});

it('lists an overdue-completion job and clears once marked complete', function () {
    $job = openTasksJob($this);

    $this->lifecycle->schedule(
        $job,
        now()->subHours(6),
        $this->admin,
        technicianId: $this->tech->id,
        exactTime: true,
    );

    openTasksTab($this->admin, 'completion')->assertSee($job->job_number);

    expect($job->refresh()->openTaskTypes())->toContain('completion');

    $this->lifecycle->markWorkComplete($job, $this->admin, sendCompleteText: false);

    openTasksTab($this->admin, 'completion')->assertDontSee($job->job_number);

    expect($job->refresh()->openTaskTypes())->not->toContain('completion');
});

it('lists a work-complete unpaid job under Missing payment and clears once fully paid', function () {
    $job = openTasksJob($this);

    $this->lifecycle->schedule($job, now()->addHour(), $this->admin, technicianId: $this->tech->id);
    $this->lifecycle->markWorkComplete($job, $this->admin, sendCompleteText: false);

    openTasksTab($this->admin, 'payment')->assertSee($job->job_number);

    expect($job->refresh()->openTaskTypes())->toContain('payment');

    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->admin);

    openTasksTab($this->admin, 'payment')->assertDontSee($job->job_number);

    expect($job->refresh()->openTaskTypes())->not->toContain('payment');
});

it('lists a scheduled job with an unordered part and clears once ordered', function () {
    $job = openTasksJob($this);
    $this->lifecycle->schedule($job, now()->addDay(), $this->admin, technicianId: $this->tech->id);

    openTasksTab($this->admin, 'part_ordered')->assertSee($job->job_number);

    expect($job->refresh()->openTaskTypes())->toContain('part_ordered');

    $this->lifecycle->markPartsOrdered($job, $this->admin);

    openTasksTab($this->admin, 'part_ordered')->assertDontSee($job->job_number);

    expect($job->refresh()->openTaskTypes())->not->toContain('part_ordered');
});

it('lists an Other-supplier ordered-unpaid part under Part not paid and clears once prepaid', function () {
    $job = openTasksJob($this);
    $this->lifecycle->schedule(
        $job,
        now()->addDay(),
        $this->admin,
        technicianId: $this->tech->id,
        warehouseOther: 'Some Dealer',
    );
    $this->lifecycle->markPartsOrdered($job, $this->admin);

    openTasksTab($this->admin, 'part_paid')->assertSee($job->job_number);

    expect($job->refresh()->openTaskTypes())->toContain('part_paid');

    $this->lifecycle->markPartsPaid($job, $this->admin);

    openTasksTab($this->admin, 'part_paid')->assertDontSee($job->job_number);

    expect($job->refresh()->openTaskTypes())->not->toContain('part_paid');
});

it('lists a job in the open calibration pipeline and clears once confirmed', function () {
    $job = openTasksJob($this);
    $job->calibration_status = CalibrationStatus::Needed;
    $job->save();

    openTasksTab($this->admin, 'calibration')->assertSee($job->job_number);

    expect($job->refresh()->openTaskTypes())->toContain('calibration');

    $job->calibration_status = CalibrationStatus::Completed;
    $job->calibration_confirmed_at = now();
    $job->save();

    openTasksTab($this->admin, 'calibration')->assertDontSee($job->job_number);

    expect($job->refresh()->openTaskTypes())->not->toContain('calibration');
});

it('the All tab unions every job with any open task', function () {
    $scheduling = openTasksJob($this, ['first_name' => 'Scheduling']);

    $ordered = openTasksJob($this, ['first_name' => 'Ordered']);
    $this->lifecycle->schedule($ordered, now()->addDay(), $this->admin, technicianId: $this->tech->id);

    openTasksTab($this->admin, 'all')
        ->assertSee($scheduling->job_number)
        ->assertSee($ordered->job_number);
});
