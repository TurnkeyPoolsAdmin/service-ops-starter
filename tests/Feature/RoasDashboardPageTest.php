<?php

use App\Filament\Pages\RoasDashboard;
use App\Models\AdMetric;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'a@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['manager'],
    ])->assignRole('super_admin');

    $this->dispatch = Employee::create([
        'name' => 'Dispatch', 'email' => 'd@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->dispatch->assignRole('dispatch');
});

it('renders the ROAS dashboard for super_admin with computed metrics', function () {
    // Freeze "now" so the seeded metrics fall inside the default "Last 7 days" window.
    Carbon::setTestNow(Carbon::parse('2026-06-20 12:00', 'America/Los_Angeles'));

    $la = Entity::where('code', 'LA')->first();
    $la->update(['google_ads_customer_id' => '1234567890']);

    AdMetric::create(['entity_id' => $la->id, 'date' => '2026-06-18', 'cost_micros' => 250_000_000, 'conversions' => 5]);
    $customer = Customer::create(['phone_e164' => '+18185551234', 'first_name' => 'Al']);
    Job::create([
        'job_number' => 'LA-2026-0001', 'entity_id' => $la->id, 'customer_id' => $customer->id,
        'status' => 'completed', 'amount_collected_cents' => 90000, 'part_cost_cents' => 20000,
        'tech_pay_amount_cents' => 30000,
        'payment_received_at' => Carbon::parse('2026-06-18 14:00', 'America/Los_Angeles')->utc(),
    ]);

    $this->actingAs($this->admin)
        ->get(RoasDashboard::getUrl())
        ->assertSuccessful()
        ->assertSee('Ad spend')   // grand-total summary card
        ->assertSee('$250.00')    // spend
        ->assertSee('$50.00');    // cost per lead ($250 / 5)

    Carbon::setTestNow();
});

it('blocks dispatch from the ROAS dashboard', function () {
    $this->actingAs($this->dispatch)
        ->get(RoasDashboard::getUrl())
        ->assertForbidden();
});
