<?php

use App\Filament\Pages\JobCalendar;
use App\Filament\Resources\Jobs\Pages\ViewJob;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Services\JobLifecycle;
use App\Support\Timezone;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->dispatch = Employee::create([
        'name' => 'Dispatch', 'email' => 'd@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->dispatch->assignRole('dispatch');
});

it('renders the calendar with a scheduled job for dispatch', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00', 'America/Los_Angeles'));

    $la = Entity::where('code', 'LA')->first();
    $customer = Customer::create(['phone_e164' => '+18185551234', 'first_name' => 'Al', 'last_name' => 'Pacino']);
    Job::create([
        'job_number' => 'LA-2026-0001', 'entity_id' => $la->id, 'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Al Pacino', 'status' => 'scheduled',
        'scheduled_at' => Carbon::parse('2026-06-15 09:30', 'America/Los_Angeles')->utc(),
    ]);

    // Defaults to the week view; June 15 2026 (Monday) starts the Jun 15–20 week.
    $this->actingAs($this->dispatch)
        ->get(JobCalendar::getUrl())
        ->assertSuccessful()
        ->assertSee('Jun 15')
        // Blocks show the customer's first name only.
        ->assertSee('Al')
        ->assertDontSee('Al Pacino');

    Carbon::setTestNow();
});

it('filters the calendar by technician', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00', 'America/Los_Angeles'));

    $la = Entity::where('code', 'LA')->first();
    $customer = Customer::create(['phone_e164' => '+18185550000', 'first_name' => 'Cust']);

    $tech = Employee::create(['name' => 'Tina Tech', 'active' => true, 'job_functions' => ['technician']]);
    $other = Employee::create(['name' => 'Oscar Other', 'active' => true, 'job_functions' => ['technician']]);

    Job::create([
        'job_number' => 'LA-2026-1001', 'entity_id' => $la->id, 'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Bella Shown', 'status' => 'scheduled', 'technician_id' => $tech->id,
        'scheduled_at' => Carbon::parse('2026-06-16 09:30', 'America/Los_Angeles')->utc(),
    ]);
    Job::create([
        'job_number' => 'LA-2026-1002', 'entity_id' => $la->id, 'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Zed Hidden', 'status' => 'scheduled', 'technician_id' => $other->id,
        'scheduled_at' => Carbon::parse('2026-06-17 09:30', 'America/Los_Angeles')->utc(),
    ]);

    Livewire::actingAs($this->dispatch)
        ->test(JobCalendar::class)
        ->fillForm(['technician_id' => $tech->id])
        ->assertSee('Bella')
        ->assertDontSee('Zed');

    Carbon::setTestNow();
});

it('schedules a job from the separate date and time fields', function () {
    $la = Entity::where('code', 'LA')->first();
    $customer = Customer::create(['phone_e164' => '+18185558888', 'first_name' => 'Sked']);
    $tech = Employee::create([
        'name' => 'Sked Tech', 'email' => 'sked-tech@test.local', 'can_login' => false,
        'active' => true, 'password' => bcrypt('x'), 'job_functions' => ['technician'],
    ]);
    $job = Job::create([
        'job_number' => 'LA-2026-3001', 'entity_id' => $la->id, 'customer_id' => $customer->id,
        'status' => 'pending_schedule',
    ]);

    Livewire::actingAs($this->dispatch)
        ->test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('schedule', data: [
            'scheduled_date' => '2026-07-04',
            'scheduled_time' => '14:30',
            'technician_id' => $tech->id,
            'warehouse_id' => 'other',
            'warehouse_other' => 'Dealer',
        ])
        ->assertHasNoActionErrors();

    // 2:30 PM Pacific on Jul 4 2026 (PDT, UTC-7) = 21:30 UTC.
    $stored = $job->fresh()->scheduled_at;
    expect($stored->utc()->format('Y-m-d H:i'))->toBe('2026-07-04 21:30');
    expect(Timezone::toDisplay($stored)->format('Y-m-d H:i'))->toBe('2026-07-04 14:30');
});

it('auto-fills tech pay from the technician fixed cost on assignment', function () {
    $la = Entity::where('code', 'LA')->first();
    $customer = Customer::create(['phone_e164' => '+18185559999', 'first_name' => 'Fix']);
    $tech = Employee::create([
        'name' => 'Flat Rate Fred', 'active' => true, 'job_functions' => ['technician'],
        'fixed_cost_cents' => 12000,
    ]);
    $job = Job::create([
        'job_number' => 'LA-2026-2001', 'entity_id' => $la->id, 'customer_id' => $customer->id,
        'status' => 'pending_schedule',
    ]);

    app(JobLifecycle::class)->assignTechnician($job, $tech->id, $this->dispatch);

    expect($job->fresh()->tech_pay_amount_cents)->toBe(12000);
});
