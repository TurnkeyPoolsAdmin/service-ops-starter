<?php

use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Services\JobLifecycle;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WarehouseSeeder;

beforeEach(function () {
    $this->seed([
        RoleSeeder::class,
        EntitySeeder::class,
        WarehouseSeeder::class,
        MessageTemplateSeeder::class,
        SettingSeeder::class,
    ]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->lifecycle = app(JobLifecycle::class);
});

function partialTech(): Employee
{
    return Employee::create([
        'name' => 'Tess Tech', 'email' => 'tess-partial@test.local', 'can_login' => false,
        'active' => true, 'password' => bcrypt('x'), 'job_functions' => ['technician'],
    ]);
}

function partialJob($test): Job
{
    // quote 95000
    $job = $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Alan',
        'last_name' => 'Fairview',
        'vehicle_year' => '2022', 'vehicle_make' => 'Tesla', 'vehicle_model' => 'Model Y',
        'vin' => '7SAYGDEF3NF000287', 'glass_position' => 'windshield',
        'quote_amount_cents' => 95000, 'part_cost_cents' => 35000,
        'requested_daytime_raw' => 'Thursday',
        'intake_raw_text' => 'raw', 'parsed_payload_json' => ['x' => 1],
        'parse_status' => 'clean',
    ], $test->actor);
    $job->update(['technician_id' => partialTech()->id]);

    return $job->refresh();
}

it('records a partial payment and leaves a balance due', function () {
    $job = partialJob($this);

    $this->lifecycle->recordPayment($job, 36250, PaymentMethod::SwiftSwipeXpro, null, null, $this->actor);
    $job->refresh();

    expect($job->isPaid())->toBeTrue()
        ->and($job->isFullyPaid())->toBeFalse()
        ->and($job->isPartiallyPaid())->toBeTrue()
        ->and($job->amount_collected_cents)->toBe(36250)
        ->and($job->balanceDueCents())->toBe(95000 - 36250)
        ->and($job->payments()->count())->toBe(1)
        ->and($job->payment_method)->toBe(PaymentMethod::SwiftSwipeXpro);
});

it('does not complete a work-done job until the full balance is collected', function () {
    $job = partialJob($this);
    $this->lifecycle->markWorkComplete($job, $this->actor, sendCompleteText: false);
    $this->lifecycle->recordPayment($job->refresh(), 36250, PaymentMethod::SwiftSwipeXpro, null, null, $this->actor);
    $this->lifecycle->markTechPaid($job->refresh(), 20000, PaymentMethod::Venmo, 'v-1', $this->actor);
    $job->refresh();

    // Balance still due → stays WorkCompleted, not Completed.
    expect($job->status)->toBe(JobStatus::WorkCompleted)
        ->and($job->completed_at)->toBeNull();

    // Collect the rest → now fully paid → completes.
    $this->lifecycle->recordPayment($job, 95000 - 36250, PaymentMethod::Zelle, null, null, $this->actor);
    $job->refresh();

    expect($job->isFullyPaid())->toBeTrue()
        ->and($job->status)->toBe(JobStatus::Completed)
        ->and($job->amount_collected_cents)->toBe(95000)
        ->and($job->payments()->count())->toBe(2);
});

it('accumulates the collected total across payment lines', function () {
    $job = partialJob($this);

    $this->lifecycle->recordPayment($job, 40000, PaymentMethod::Zelle, null, null, $this->actor);
    $this->lifecycle->recordPayment($job->refresh(), 55000, PaymentMethod::Cash, null, null, $this->actor);
    $job->refresh();

    expect($job->amount_collected_cents)->toBe(95000)
        ->and($job->balanceDueCents())->toBe(0)
        ->and($job->payment_method)->toBe(PaymentMethod::Cash); // latest line
});

it('only rides the cash line onto the tech balance, not the whole total', function () {
    $tech = Employee::find(partialJob($this)->technician_id);
    $job = Job::where('technician_id', $tech->id)->first();

    // Non-cash deposit first, then a cash line.
    $this->lifecycle->recordPayment($job, 36250, PaymentMethod::SwiftSwipeXpro, null, null, $this->actor);
    $this->lifecycle->recordPayment($job->refresh(), 20000, PaymentMethod::Cash, null, null, $this->actor);

    // Only the $200 cash rides onto the balance — not the $562.50 total.
    expect((int) $tech->refresh()->cash_balance_cents)->toBe(20000);
});

it('removing a payment re-syncs the total and reverses cash', function () {
    $tech = Employee::find(partialJob($this)->technician_id);
    $job = Job::where('technician_id', $tech->id)->first();

    $this->lifecycle->recordPayment($job, 30000, PaymentMethod::Zelle, null, null, $this->actor);
    $cash = $this->lifecycle->recordPayment($job->refresh(), 20000, PaymentMethod::Cash, null, null, $this->actor)
        ->payments()->where('method', PaymentMethod::Cash->value)->first();

    expect((int) $tech->refresh()->cash_balance_cents)->toBe(20000);

    $this->lifecycle->deletePayment($cash, $this->actor);
    $job->refresh();

    expect($job->amount_collected_cents)->toBe(30000)
        ->and($job->payment_method)->toBe(PaymentMethod::Zelle)
        ->and($job->payments()->count())->toBe(1)
        ->and((int) $tech->refresh()->cash_balance_cents)->toBe(0);
});

it('removing the only payment marks the job unpaid again', function () {
    $job = partialJob($this);
    $payment = $this->lifecycle->recordPayment($job, 36250, PaymentMethod::Zelle, null, null, $this->actor)
        ->payments()->first();

    $this->lifecycle->deletePayment($payment, $this->actor);
    $job->refresh();

    expect($job->isPaid())->toBeFalse()
        ->and($job->amount_collected_cents)->toBe(0)
        ->and($job->payment_method)->toBeNull()
        ->and($job->payment_received_at)->toBeNull();
});

it('keeps a job owed-from-Xpro for the Xpro portion when the balance is paid another way', function () {
    $job = partialJob($this);

    // Xpro collected half; the customer paid the balance directly by Zelle.
    $this->lifecycle->recordPayment($job, 36250, PaymentMethod::SwiftSwipeXpro, null, null, $this->actor);
    $this->lifecycle->recordPayment($job->refresh(), 36250, PaymentMethod::Zelle, null, null, $this->actor);
    $job->refresh();

    // Summary method flipped to the latest (Zelle) — but the job must still be
    // owed from Xpro for the SwiftSwipe portion, not silently drop off.
    expect($job->payment_method)->toBe(PaymentMethod::Zelle)
        ->and($job->isOwedFromXpro())->toBeTrue()
        ->and($job->xproOwedCents())->toBe(36250)
        ->and($job->xproMethodsLabel())->toBe(PaymentMethod::SwiftSwipeXpro->label())
        ->and(Job::owedFromXpro()->whereKey($job->id)->exists())->toBeTrue();

    // Marking it collected clears it (the whole job's Xpro money is now in hand).
    $this->lifecycle->markXproCollected($job, $this->actor);
    $job->refresh();

    expect($job->isOwedFromXpro())->toBeFalse()
        ->and($job->xproOwedCents())->toBe(0)
        ->and(Job::owedFromXpro()->whereKey($job->id)->exists())->toBeFalse();
});

it('surfaces partially-paid work-complete jobs in Awaiting payment', function () {
    $job = partialJob($this);
    $this->lifecycle->markWorkComplete($job, $this->actor, sendCompleteText: false);
    $this->lifecycle->recordPayment($job->refresh(), 36250, PaymentMethod::Zelle, null, null, $this->actor);

    expect(Job::awaitingPayment()->whereKey($job->id)->exists())->toBeTrue();

    // Collecting the balance drops it off the awaiting list.
    $this->lifecycle->recordPayment($job->refresh(), 95000 - 36250, PaymentMethod::Zelle, null, null, $this->actor);
    expect(Job::awaitingPayment()->whereKey($job->id)->exists())->toBeFalse();
});
