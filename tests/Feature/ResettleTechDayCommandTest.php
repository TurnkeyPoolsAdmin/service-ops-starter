<?php

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\TechSettlement;
use App\Services\JobLifecycle;
use App\Support\Timezone;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'a@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ])->assignRole('super_admin');

    $this->tech = Employee::create([
        'name' => 'Tina Tech', 'email' => 't@test.local', 'can_login' => false, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['technician'],
    ]);

    $this->lifecycle = app(JobLifecycle::class);
});

function resettleJob(int $techId, int $payCents): Job
{
    $la = Entity::where('code', 'LA')->first();
    $customer = Customer::create(['phone_e164' => '+1818555'.random_int(1000, 9999), 'first_name' => 'Cust']);

    return Job::create([
        'job_number' => 'LA-2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'entity_id' => $la->id, 'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Cust Omer',
        'status' => 'work_completed', 'technician_id' => $techId,
        'tech_pay_amount_cents' => $payCents,
        'work_completed_at' => Timezone::now()->utc(),
        'payment_received_at' => Timezone::now()->utc(),
        'amount_collected_cents' => 40000,
        'payment_method' => 'zelle',
    ]);
}

it('backfills a legacy settlement missing settled_job_ids, then re-settles to pick up a late part flag', function () {
    // Reproduces Alex's day: settled before a tech-fronted part was flagged.
    $job = resettleJob($this->tech->id, 12000);
    $this->lifecycle->recordPayment($job, 30000, PaymentMethod::Cash, null, null, $this->admin);

    $today = Timezone::now()->format('Y-m-d');
    // recordPayment moved the balance via a separate model instance — refresh
    // before handing $this->tech to settleTechDay, same as the UI action does
    // by always re-querying a fresh Employee record.
    $this->lifecycle->settleTechDay($this->tech->refresh(), $today, null, null, null, $this->admin);
    expect($this->tech->refresh()->cashOwedCents())->toBe(18000); // $300 - $120

    // Simulate a legacy row (created before settled_job_ids existed).
    $settlement = TechSettlement::where('technician_id', $this->tech->id)->where('type', 'eod_payout')->first();
    $settlement->settled_job_ids = null;
    $settlement->saveQuietly();

    // The $55 part gets flagged after the fact.
    $job->update(['tech_parts_cost_cents' => 5500]);

    $this->artisan('settlements:resettle', ['technician_id' => $this->tech->id, 'date' => $today])
        ->assertSuccessful();

    expect($this->tech->refresh()->cashOwedCents())->toBe(12500); // $300 - $120 - $55
    expect($job->refresh()->isTechPaid())->toBeTrue();

    expect($settlement->refresh()->isUndone())->toBeTrue();
    $latest = TechSettlement::where('technician_id', $this->tech->id)->where('type', 'eod_payout')->whereNull('undone_at')->first();
    expect($latest->tech_pay_owed_cents)->toBe(17500); // $120 labor + $55 part
});

it('fails loudly rather than guessing when the reconstructed job set does not match job_count', function () {
    $job = resettleJob($this->tech->id, 12000);
    $today = Timezone::now()->format('Y-m-d');
    $this->lifecycle->settleTechDay($this->tech, $today, null, null, null, $this->admin);

    $settlement = TechSettlement::where('technician_id', $this->tech->id)->where('type', 'eod_payout')->first();
    $settlement->settled_job_ids = null;
    $settlement->job_count = 2; // Pretend two jobs were settled — only one matches by tech_paid_at.
    $settlement->saveQuietly();

    $this->artisan('settlements:resettle', ['technician_id' => $this->tech->id, 'date' => $today])
        ->assertFailed();

    expect($settlement->refresh()->isUndone())->toBeFalse();
});
