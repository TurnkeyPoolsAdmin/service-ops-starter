<?php

use App\Filament\Pages\TechPayments;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
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
    ])->assignRole('dispatch');

    $this->tech = Employee::create([
        'name' => 'Tina Tech', 'email' => 't@test.local', 'can_login' => false, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['technician'],
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function navJob(int $techId, int $payCents, Carbon $completedAtLocal): Job
{
    $la = Entity::where('code', 'LA')->first();
    $customer = Customer::create(['phone_e164' => '+1818555'.random_int(1000, 9999), 'first_name' => 'Cust']);

    return Job::create([
        'job_number' => 'LA-2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'entity_id' => $la->id, 'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Cust Omer',
        'status' => 'work_completed', 'technician_id' => $techId,
        'tech_pay_amount_cents' => $payCents,
        'work_completed_at' => $completedAtLocal->copy()->utc(),
    ]);
}

it('shows the correct day after Previous/Previous/Next navigation', function () {
    // "Now" = July 9, 2026, 9am Pacific.
    Carbon::setTestNow(Carbon::parse('2026-07-09 09:00:00', Timezone::display()));

    // July 7 has a $70 job; July 8 has NO jobs; July 9 (today) has none yet.
    navJob($this->tech->id, 7000, Carbon::parse('2026-07-07 14:00:00', Timezone::display()));

    $page = Livewire::actingAs($this->dispatch)->test(TechPayments::class);

    // Defaults to today (July 9): empty.
    expect($page->get('date'))->toBe('2026-07-09');
    $page->assertDontSee('$70.00');

    // Previous -> July 8: still empty.
    $page->callAction('previousDay');
    expect($page->get('date'))->toBe('2026-07-08');
    $page->assertDontSee('$70.00');

    // Previous -> July 7: shows the $70 job.
    $page->callAction('previousDay');
    expect($page->get('date'))->toBe('2026-07-07');
    $page->assertSee('$70.00');

    // Next -> July 8: must be EMPTY again (no July 8 jobs). This is the reported bug:
    // it wrongly shows July 7's job under the July 8 label.
    $page->callAction('nextDay');
    expect($page->get('date'))->toBe('2026-07-08');
    $page->assertDontSee('$70.00');
});
