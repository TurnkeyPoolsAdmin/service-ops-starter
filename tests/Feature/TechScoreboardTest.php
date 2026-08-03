<?php

use App\Filament\Pages\Analytics;
use App\Filament\Pages\TechScoreboard;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Support\Timezone;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->owner = Employee::create([
        'name' => 'Owner', 'email' => 'owner@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->owner->assignRole('super_admin');

    $this->tech = Employee::create([
        'name' => 'Ranked Rick', 'email' => 'rick@test.local', 'active' => true,
        'job_functions' => ['technician'], 'phone' => '+18185550000',
        'priority' => 1, 'fixed_cost_cents' => 10000,
    ]);

    $this->actingAs($this->owner);
});

/** A completed job for the tech, N local days ago, with the given labor pay. */
function scoreboardJob($test, int $daysAgo, int $payCents, int $extraCents = 0): Job
{
    $customer = Customer::create(['phone_e164' => '+1818555'.str_pad((string) random_int(1000, 9999), 4, '0')]);
    $completedAt = Timezone::now()->subDays($daysAgo)->setTime(12, 0)->utc();

    return Job::create([
        'job_number' => 'LA-2026-'.random_int(10000, 99999),
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Someone',
        'status' => 'completed',
        'technician_id' => $test->tech->id,
        'work_completed_at' => $completedAt,
        'tech_pay_amount_cents' => $payCents,
        'additional_labor_cents' => $extraCents,
    ]);
}

it('totals jobs and labor paid for the selected window', function () {
    scoreboardJob($this, 0, 10000);
    scoreboardJob($this, 3, 10000, 5000);   // a bonus day, inside last 7
    scoreboardJob($this, 20, 10000);        // inside last 30 only

    $today = Livewire::test(TechScoreboard::class, ['range' => 'today'])->assertOk();
    $row = $today->instance()->getTable()->getRecords()->first();
    expect((int) $row->window_jobs_count)->toBe(1)
        ->and((int) $row->window_paid_cents)->toBe(10000);

    $week = Livewire::test(TechScoreboard::class, ['range' => 'last_7']);
    $row = $week->instance()->getTable()->getRecords()->first();
    expect((int) $row->window_jobs_count)->toBe(2)
        ->and((int) $row->window_paid_cents)->toBe(25000);

    $month = Livewire::test(TechScoreboard::class, ['range' => 'last_30']);
    $row = $month->instance()->getTable()->getRecords()->first();
    expect((int) $row->window_jobs_count)->toBe(3)
        ->and((int) $row->window_paid_cents)->toBe(35000);
});

it('scopes yesterday to that day alone', function () {
    scoreboardJob($this, 0, 10000);
    scoreboardJob($this, 1, 12000);

    $page = Livewire::test(TechScoreboard::class, ['range' => 'yesterday']);
    $row = $page->instance()->getTable()->getRecords()->first();

    expect((int) $row->window_jobs_count)->toBe(1)
        ->and((int) $row->window_paid_cents)->toBe(12000);
});

it('excludes canceled and lost jobs', function () {
    scoreboardJob($this, 0, 10000);
    scoreboardJob($this, 0, 9999)->update(['status' => 'canceled']);

    $page = Livewire::test(TechScoreboard::class, ['range' => 'today']);
    $row = $page->instance()->getTable()->getRecords()->first();

    expect((int) $row->window_jobs_count)->toBe(1)
        ->and((int) $row->window_paid_cents)->toBe(10000);
});

it('filters by tech pool — SFV/AGLA in one view, Del Mar in another', function () {
    scoreboardJob($this, 0, 10000);
    $this->tech->update(['entity_id' => Entity::where('code', 'SFV')->value('id')]);

    // A Del Mar tech with their own completed job today.
    $delMar = Employee::create([
        'name' => 'Del Mar Dana', 'email' => 'dana@test.local', 'active' => true,
        'job_functions' => ['technician'], 'entity_id' => Entity::where('code', 'DelMar')->value('id'),
    ]);
    $original = $this->tech;
    $this->tech = $delMar;
    scoreboardJob($this, 0, 8000);
    $this->tech = $original;

    $names = fn (array $filters) => Livewire::test(TechScoreboard::class, ['range' => 'today'])
        ->set('tableFilters', $filters)
        ->instance()->getTable()->getRecords()->pluck('name')->all();

    expect($names([]))->toContain('Ranked Rick', 'Del Mar Dana')
        // LA and SFV share one crew, so an LA-registered tech rides the same option.
        ->and($names(['pool' => ['value' => 'sfv_agla']]))->toBe(['Ranked Rick'])
        ->and($names(['pool' => ['value' => 'del_mar_auto_glass']]))->toBe(['Del Mar Dana']);
});

it('is a tile on the Analytics hub and hidden from non-super-admins', function () {
    scoreboardJob($this, 0, 10000);

    expect(TechScoreboard::canAccess())->toBeTrue()
        ->and(collect(Analytics::visibleTiles())->pluck('label'))->toContain('Tech Scoreboard');

    $dispatcher = Employee::create([
        'name' => 'Dee', 'email' => 'dee@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $dispatcher->assignRole('dispatch');
    $this->actingAs($dispatcher);

    expect(TechScoreboard::canAccess())->toBeFalse();
});
