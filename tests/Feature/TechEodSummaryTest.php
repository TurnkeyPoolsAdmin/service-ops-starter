<?php

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\ScheduledMessage;
use App\Models\WarrantyClaim;
use App\Services\JobLifecycle;
use App\Services\Messaging\TechEodSummaryScheduler;
use App\Support\Timezone;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, SettingSeeder::class]);

    $this->dispatch = Employee::create([
        'name' => 'Dispatch', 'email' => 'd@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ])->assignRole('dispatch');

    $this->tech = Employee::create([
        'name' => 'Tina Tech', 'email' => 't@test.local', 'can_login' => false, 'active' => true,
        'phone' => '+18185550000', 'job_functions' => ['technician'],
        'eod_summary_texts_enabled' => true,
    ]);

    $this->lifecycle = app(JobLifecycle::class);
    $this->scheduler = app(TechEodSummaryScheduler::class);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A completed job for the given tech, with a vehicle label so it shows up in
 * the summary body. Mirrors TechPaymentsPageTest's dayJob() helper.
 */
function eodJob(int $techId, int $payCents, ?Carbon $completedAt = null, array $attrs = []): Job
{
    $la = Entity::where('code', 'LA')->first();
    $customer = Customer::create(['phone_e164' => '+1818555'.random_int(1000, 9999), 'first_name' => 'Cust']);
    $completedAt ??= Timezone::now();

    return Job::create(array_merge([
        'job_number' => 'LA-2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'entity_id' => $la->id, 'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Cust Omer',
        'status' => 'work_completed', 'technician_id' => $techId,
        'tech_pay_amount_cents' => $payCents,
        'work_completed_at' => $completedAt->utc(),
        'vehicle_year' => '2019', 'vehicle_make' => 'Honda', 'vehicle_model' => 'Civic',
    ], $attrs));
}

it('sends nothing when the toggle is off, even with completed jobs', function () {
    $this->tech->update(['eod_summary_texts_enabled' => false]);
    eodJob($this->tech->id, 15000);

    $msgs = $this->scheduler->run(Timezone::now());

    expect($msgs)->toHaveCount(0)
        ->and(ScheduledMessage::count())->toBe(0);
});

it('sends nothing when the tech completed no job and no paid warranty visit that day', function () {
    $msgs = $this->scheduler->run(Timezone::now());

    expect($msgs)->toHaveCount(0);
});

it('sends one message summarizing two completed jobs', function () {
    $this->tech->update(['preferred_language' => 'en']);
    eodJob($this->tech->id, 15000, null, [
        'vehicle_year' => '2019', 'vehicle_make' => 'Honda', 'vehicle_model' => 'Civic',
    ]);
    eodJob($this->tech->id, 17500, null, [
        'vehicle_year' => '2021', 'vehicle_make' => 'Ford', 'vehicle_model' => 'F-150',
    ]);

    $msgs = $this->scheduler->run(Timezone::now());

    expect($msgs)->toHaveCount(1);
    $body = $msgs[0]->rendered_body;

    expect($body)->toContain('2019 Honda Civic')
        ->and($body)->toContain('$150.00')
        ->and($body)->toContain('2021 Ford F-150')
        ->and($body)->toContain('$175.00')
        ->and($body)->toContain('Total: $325.00')
        ->and($body)->toContain('Net pay for today: $325.00');
});

it('shows cash collected that day, its vehicle, the cash total, and the netted pay', function () {
    $this->tech->update(['preferred_language' => 'en']);
    $job = eodJob($this->tech->id, 15000);

    $this->lifecycle->recordPayment($job, 20000, PaymentMethod::Cash, null, null, $this->dispatch);

    $msgs = $this->scheduler->run(Timezone::now());
    expect($msgs)->toHaveCount(1);
    $body = $msgs[0]->rendered_body;

    expect($body)->toContain('Cash collected:')
        ->and($body)->toContain('$200.00 (2019 Honda Civic)')
        ->and($body)->toContain('Cash total: $200.00')
        // Total labor $150 minus $200 cash collected = -$50 net.
        ->and($body)->toContain('Net pay for today: -$50.00')
        ->and($body)->toContain('You collected $50.00 more in cash than today\'s pay');
});

it('leads with a prior balance and explains the payout math in the closing line', function () {
    $this->tech->update(['preferred_language' => 'en', 'cash_balance_cents' => 10000]);
    eodJob($this->tech->id, 15000);

    $msgs = $this->scheduler->run(Timezone::now());
    expect($msgs)->toHaveCount(1);
    $body = $msgs[0]->rendered_body;

    expect($body)->toContain('Balance owed from before: $100.00')
        ->and($body)->toContain('Net pay for today: $150.00')
        // $150 net - $100 prior balance = $50 expected payout.
        ->and($body)->toContain('Your balance owed of $100.00 will be deducted, so your expected payout is $50.00.');
});

it('includes a resolved, paid warranty visit even with no completed job that day', function () {
    $this->tech->update(['preferred_language' => 'en']);

    // Build a job + warranty claim directly, resolved TODAY with tech pay.
    $la = Entity::where('code', 'LA')->first();
    $customer = Customer::create(['phone_e164' => '+18185559090', 'first_name' => 'Warr']);
    $job = Job::create([
        'job_number' => 'LA-2026-8000', 'entity_id' => $la->id, 'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Warr Anty', 'status' => 'completed', 'technician_id' => $this->tech->id,
        'vehicle_year' => '2018', 'vehicle_make' => 'Toyota', 'vehicle_model' => 'Camry',
        'work_completed_at' => Timezone::now()->subDays(3)->utc(),
    ]);
    $claim = WarrantyClaim::create([
        'job_id' => $job->id, 'customer_id' => $customer->id, 'entity_id' => $la->id,
        'status' => WarrantyClaim::STATUS_RESOLVED, 'issue' => 'Leak',
        'technician_id' => $this->tech->id, 'original_technician_id' => $this->tech->id,
        'tech_pay_cents' => 5000, 'resolved_at' => Timezone::now()->utc(),
    ]);

    $msgs = $this->scheduler->run(Timezone::now());
    expect($msgs)->toHaveCount(1);
    $body = $msgs[0]->rendered_body;

    expect($body)->toContain('Warranty visit: 2018 Toyota Camry - $50.00')
        ->and($body)->toContain('Total: $50.00')
        ->and($body)->toContain('Net pay for today: $50.00');

    expect($claim)->not->toBeNull();
});

it('queues exactly one message even when the scheduler runs twice', function () {
    eodJob($this->tech->id, 15000);

    $this->scheduler->run(Timezone::now());
    $this->scheduler->run(Timezone::now());

    expect(ScheduledMessage::where('employee_id', $this->tech->id)->count())->toBe(1);
});

it('sends the Spanish body to a tech whose language defaults to Spanish', function () {
    // preferred_language left unset — Employee::preferredLanguage() defaults to Spanish.
    eodJob($this->tech->id, 15000);

    $msgs = $this->scheduler->run(Timezone::now());
    expect($msgs)->toHaveCount(1);

    $body = $msgs[0]->rendered_body;
    expect($body)->toContain('Trabajos completados:')
        ->and($body)->toContain('Total: $150.00')
        ->and($body)->not->toContain('Jobs completed:');
});
