<?php

use App\Enums\PaymentMethod;
use App\Filament\Pages\TechPayments;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\TechSettlement;
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
    ])->assignRole('dispatch');

    $this->tech = Employee::create([
        'name' => 'Tina Tech', 'email' => 't@test.local', 'can_login' => false, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['technician'],
    ]);

    $this->lifecycle = app(JobLifecycle::class);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A completed job for the given tech. Records a non-cash (zelle) payment so it's
 * settleable but doesn't touch the tech's cash balance. To put cash on the
 * balance, record a Cash payment through JobLifecycle::recordPayment (that's the
 * only path that moves the balance now).
 */
function dayJob(int $techId, int $payCents, ?Carbon $completedAt = null): Job
{
    $la = Entity::where('code', 'LA')->first();
    $customer = Customer::create(['phone_e164' => '+1818555'.random_int(1000, 9999), 'first_name' => 'Cust']);
    $completedAt ??= Timezone::now();

    return Job::create([
        'job_number' => 'LA-2026-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'entity_id' => $la->id, 'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Cust Omer',
        'status' => 'work_completed', 'technician_id' => $techId,
        'tech_pay_amount_cents' => $payCents,
        'work_completed_at' => $completedAt->utc(),
        'payment_received_at' => $completedAt->utc(),
        'amount_collected_cents' => 40000,
        'payment_method' => 'zelle',
    ]);
}

it('shows each tech\'s pay owed and payout for the day', function () {
    dayJob($this->tech->id, 12000);            // zelle, $120 owed
    dayJob($this->tech->id, 8000);             // zelle, $80 owed

    Livewire::actingAs($this->dispatch)
        ->test(TechPayments::class)
        ->assertSuccessful()
        ->assertSee('Tina Tech')
        ->assertSee('$200.00'); // pay owed / to pay
});

it('adds parts the tech fronted to the pay owed on top of labor', function () {
    // Work $120 + tech fronted $200 in parts = $320 owed.
    $job = dayJob($this->tech->id, 12000);
    $job->update(['tech_parts_cost_cents' => 20000]);

    Livewire::actingAs($this->dispatch)
        ->test(TechPayments::class)
        ->assertSuccessful()
        ->assertSee('$320.00');
});

it('settles a day paying labor plus fronted parts', function () {
    $job = dayJob($this->tech->id, 12000);
    $job->update(['tech_parts_cost_cents' => 20000]); // tech paid $200 for parts

    Livewire::actingAs($this->dispatch)
        ->test(TechPayments::class)
        ->callTableAction('settle', $this->tech, data: ['method' => 'zelle'])
        ->assertHasNoTableActionErrors();

    $settlement = TechSettlement::where('technician_id', $this->tech->id)->where('type', 'eod_payout')->first();
    // $120 labor + $200 parts = $320 owed, nothing on balance → pay $320.
    expect($settlement->amount_cents)->toBe(32000)
        ->and($settlement->tech_pay_owed_cents)->toBe(32000);

    // Parts reimbursement stays on its own column (not folded into tech pay).
    expect($job->refresh()->tech_pay_amount_cents)->toBe(12000)
        ->and($job->tech_parts_cost_cents)->toBe(20000);
});

it('builds the job-by-job breakdown set incl. paid jobs and parts owed', function () {
    // A still-open job plus an already tech-paid job both belong in the breakdown.
    $open = dayJob($this->tech->id, 12000);
    $open->update(['tech_parts_cost_cents' => 5000]);

    $paid = dayJob($this->tech->id, 8000);
    $paid->update(['tech_paid_at' => Timezone::now()->utc()]);

    $jobs = $this->lifecycle->techDayCompletedJobs($this->tech, Timezone::now()->format('Y-m-d'));

    expect($jobs->pluck('id'))->toContain($open->id, $paid->id)
        // Work + parts owed for each job.
        ->and($open->total_owed_to_tech_cents)->toBe(17000) // $120 work + $50 parts
        ->and($paid->total_owed_to_tech_cents)->toBe(8000)  // $80 work, no parts
        ->and($open->tech_parts_reimbursement_cents)->toBe(5000);
});

it('opens the breakdown modal without error', function () {
    $job = dayJob($this->tech->id, 12000);
    $job->update(['tech_parts_cost_cents' => 5000]);

    Livewire::actingAs($this->dispatch)
        ->test(TechPayments::class)
        ->mountTableAction('breakdown', $this->tech)
        ->assertHasNoTableActionErrors();
});

it('settles a normal day: pays the full net and marks jobs tech-paid', function () {
    $a = dayJob($this->tech->id, 12000);
    $b = dayJob($this->tech->id, 8000);
    $today = Timezone::now()->format('Y-m-d');

    Livewire::actingAs($this->dispatch)
        ->test(TechPayments::class)
        ->callTableAction('settle', $this->tech, data: ['method' => 'zelle', 'reference' => 'z-1'])
        ->assertHasNoTableActionErrors();

    expect($a->refresh()->isTechPaid())->toBeTrue()
        ->and($b->refresh()->isTechPaid())->toBeTrue();

    $settlement = TechSettlement::where('technician_id', $this->tech->id)->first();
    expect($settlement->type)->toBe('eod_payout')
        ->and($settlement->for_date)->toBe($today)
        ->and($settlement->amount_cents)->toBe(20000)
        ->and($settlement->direction)->toBe('paid_to_tech')
        ->and($settlement->balance_after_cents)->toBe(0);

    expect($this->tech->refresh()->cashOwedCents())->toBe(0);
});

it('rolls surplus cash onto the balance when the tech collected more than their pay', function () {
    // Owed $120; tech collects $200 cash (lands on balance at record time).
    $job = dayJob($this->tech->id, 12000);
    $this->lifecycle->recordPayment($job, 20000, PaymentMethod::Cash, null, null, $this->dispatch);
    expect($this->tech->refresh()->cashOwedCents())->toBe(20000);

    // Settle the day: labor $120 vs $200 balance → pay $0, still owes $80.
    Livewire::actingAs($this->dispatch)
        ->test(TechPayments::class)
        ->callTableAction('settle', $this->tech, data: [])
        ->assertHasNoTableActionErrors();

    expect($this->tech->refresh()->cashOwedCents())->toBe(8000);

    $settlement = TechSettlement::where('technician_id', $this->tech->id)->where('type', 'eod_payout')->first();
    expect($settlement->amount_cents)->toBe(0)
        ->and($settlement->direction)->toBe('even')
        ->and($settlement->balance_after_cents)->toBe(8000);
});

it('adds collected cash to the tech balance immediately and logs it', function () {
    $job = dayJob($this->tech->id, 12000);
    $this->lifecycle->recordPayment($job, 20000, PaymentMethod::Cash, 'conf-1', null, $this->dispatch);

    expect($this->tech->refresh()->cashOwedCents())->toBe(20000);

    $log = TechSettlement::where('type', 'cash_collected')->first();
    expect($log)->not->toBeNull()
        ->and($log->amount_cents)->toBe(20000)
        ->and($log->job_id)->toBe($job->id)
        ->and($log->balance_before_cents)->toBe(0)
        ->and($log->balance_after_cents)->toBe(20000);
});

it('corrects a cash amount by removing the wrong line and recording the right one', function () {
    // Payments are an additive ledger now, so a correction is: remove the bad
    // line (its cash comes back off the balance) then record the right one.
    $job = dayJob($this->tech->id, 12000);
    $wrong = $this->lifecycle->recordPayment($job, 20000, PaymentMethod::Cash, null, null, $this->dispatch)
        ->payments()->first();

    $this->lifecycle->deletePayment($wrong, $this->dispatch);
    $this->lifecycle->recordPayment($job->refresh(), 18000, PaymentMethod::Cash, null, null, $this->dispatch);

    expect($this->tech->refresh()->cashOwedCents())->toBe(18000);
});

it('removes cash from the balance when a mistaken cash line is deleted', function () {
    $job = dayJob($this->tech->id, 12000);
    $cash = $this->lifecycle->recordPayment($job, 20000, PaymentMethod::Cash, null, null, $this->dispatch)
        ->payments()->first();
    expect($this->tech->refresh()->cashOwedCents())->toBe(20000);

    // Turns out it was Zelle — delete the cash line (cash comes back off), then
    // record it under the right method (Zelle never touches the balance).
    $this->lifecycle->deletePayment($cash, $this->dispatch);
    $this->lifecycle->recordPayment($job->refresh(), 20000, PaymentMethod::Zelle, null, null, $this->dispatch);
    expect($this->tech->refresh()->cashOwedCents())->toBe(0);
});

it('does not move the balance for a non-cash payment', function () {
    $job = dayJob($this->tech->id, 12000);
    $this->lifecycle->recordPayment($job, 20000, PaymentMethod::Zelle, null, null, $this->dispatch);

    expect($this->tech->refresh()->cashOwedCents())->toBe(0);
});

it('requires a technician before recording payment', function () {
    $la = Entity::where('code', 'LA')->first();
    $customer = Customer::create(['phone_e164' => '+18185550003', 'first_name' => 'Cust']);
    $job = Job::create([
        'job_number' => 'LA-2026-9003', 'entity_id' => $la->id, 'customer_id' => $customer->id,
        'status' => 'work_completed', 'tech_pay_amount_cents' => 12000,
        'work_completed_at' => Timezone::now()->utc(),
    ]);

    $this->lifecycle->recordPayment($job, 20000, PaymentMethod::Cash, null, null, $this->dispatch);
})->throws(RuntimeException::class);

it('requires a technician before marking work complete', function () {
    $la = Entity::where('code', 'LA')->first();
    $customer = Customer::create(['phone_e164' => '+18185550004', 'first_name' => 'Cust']);
    $job = Job::create([
        'job_number' => 'LA-2026-9004', 'entity_id' => $la->id, 'customer_id' => $customer->id,
        'status' => 'scheduled', 'tech_pay_amount_cents' => 12000,
    ]);

    $this->lifecycle->markWorkComplete($job, $this->dispatch);
})->throws(RuntimeException::class);

it('keeps a settled tech visible with a Paid status for that day', function () {
    dayJob($this->tech->id, 12000);

    $page = Livewire::actingAs($this->dispatch)->test(TechPayments::class);
    $page->callTableAction('settle', $this->tech, data: ['method' => 'zelle'])
        ->assertHasNoTableActionErrors();

    // Still listed for the day, now marked Paid rather than vanishing.
    $page->assertSuccessful()
        ->assertSee('Tina Tech')
        ->assertSee('Paid');
});

it('auto-offsets a prior owed balance against a day we owe the tech', function () {
    // Tech already owes us $80 from before.
    $this->tech->update(['cash_balance_cents' => 8000]);

    // Today: owed $200, no cash. Payout = 200 - 80 = $120; balance clears to 0.
    dayJob($this->tech->id, 20000);

    Livewire::actingAs($this->dispatch)
        ->test(TechPayments::class)
        ->callTableAction('settle', $this->tech, data: ['method' => 'zelle'])
        ->assertHasNoTableActionErrors();

    expect($this->tech->refresh()->cashOwedCents())->toBe(0);

    $settlement = TechSettlement::where('technician_id', $this->tech->id)->first();
    expect($settlement->amount_cents)->toBe(12000)
        ->and($settlement->balance_before_cents)->toBe(8000)
        ->and($settlement->balance_after_cents)->toBe(0);
});

it('only settles jobs from the selected day', function () {
    // Yesterday's job should not be touched when settling today.
    $yesterday = dayJob($this->tech->id, 5000, completedAt: Timezone::now()->subDay());
    $todayJob = dayJob($this->tech->id, 12000);

    Livewire::actingAs($this->dispatch)
        ->test(TechPayments::class)
        ->callTableAction('settle', $this->tech, data: ['method' => 'zelle'])
        ->assertHasNoTableActionErrors();

    expect($todayJob->refresh()->isTechPaid())->toBeTrue()
        ->and($yesterday->refresh()->isTechPaid())->toBeFalse();
});

it('shows a completed job even when the customer has not paid yet', function () {
    // We pay techs for jobs they complete — customer payment does not gate it.
    $la = Entity::where('code', 'LA')->first();
    $customer = Customer::create(['phone_e164' => '+18185550001', 'first_name' => 'Cust']);
    Job::create([
        'job_number' => 'LA-2026-9001', 'entity_id' => $la->id, 'customer_id' => $customer->id,
        'status' => 'work_completed', 'technician_id' => $this->tech->id,
        'tech_pay_amount_cents' => 12000, 'work_completed_at' => Timezone::now()->utc(),
        // No payment_received_at — still shows, with $0 cash collected.
    ]);

    Livewire::actingAs($this->dispatch)
        ->test(TechPayments::class)
        ->assertSuccessful()
        ->assertSee('Tina Tech')
        ->assertSee('$120.00'); // full pay owed, nothing netted
});

it('settles a completed job the customer has not paid for (full labor, no cash)', function () {
    $la = Entity::where('code', 'LA')->first();
    $customer = Customer::create(['phone_e164' => '+18185550002', 'first_name' => 'Cust']);
    $job = Job::create([
        'job_number' => 'LA-2026-9002', 'entity_id' => $la->id, 'customer_id' => $customer->id,
        'status' => 'work_completed', 'technician_id' => $this->tech->id,
        'tech_pay_amount_cents' => 12000, 'work_completed_at' => Timezone::now()->utc(),
    ]);

    Livewire::actingAs($this->dispatch)
        ->test(TechPayments::class)
        ->callTableAction('settle', $this->tech, data: ['method' => 'zelle'])
        ->assertHasNoTableActionErrors();

    expect($job->refresh()->isTechPaid())->toBeTrue();

    $settlement = TechSettlement::where('technician_id', $this->tech->id)->first();
    expect($settlement->amount_cents)->toBe(12000)
        ->and($settlement->cash_collected_cents)->toBe(0);
});

it('labels the action Mark confirmed (not Mark paid) when the day settles against a balance we owe from', function () {
    // Tech collected more cash than they're owed today — nothing to hand them.
    $job = dayJob($this->tech->id, 12000);
    $this->lifecycle->recordPayment($job, 20000, PaymentMethod::Cash, null, null, $this->dispatch);

    Livewire::actingAs($this->dispatch)
        ->test(TechPayments::class)
        ->assertSee('Mark confirmed')
        ->assertDontSee('Mark paid');
});

it('labels the action Mark paid when the day nets to an actual payout', function () {
    dayJob($this->tech->id, 12000); // zelle, no balance — full $120 payout.

    Livewire::actingAs($this->dispatch)
        ->test(TechPayments::class)
        ->assertSee('Mark paid')
        ->assertDontSee('Mark confirmed');
});

it('undoes a settlement, reopens the jobs, and restores the prior balance', function () {
    $job = dayJob($this->tech->id, 12000);
    $this->lifecycle->recordPayment($job, 20000, PaymentMethod::Cash, null, null, $this->dispatch);
    expect($this->tech->refresh()->cashOwedCents())->toBe(20000);

    $page = Livewire::actingAs($this->dispatch)->test(TechPayments::class);
    $page->callTableAction('settle', $this->tech, data: [])->assertHasNoTableActionErrors();

    // Labor $120 vs $200 balance → pays $0, balance drops to $80.
    expect($job->refresh()->isTechPaid())->toBeTrue();
    expect($this->tech->refresh()->cashOwedCents())->toBe(8000);

    $page->callTableAction('undoSettle', $this->tech)->assertHasNoTableActionErrors();

    // Job reopens and the balance goes back to exactly what it was before.
    expect($job->refresh()->isTechPaid())->toBeFalse();
    expect($this->tech->refresh()->cashOwedCents())->toBe(20000);

    $settlement = TechSettlement::where('technician_id', $this->tech->id)->where('type', 'eod_payout')->first();
    expect($settlement->isUndone())->toBeTrue();
});

it('lets a corrected tech-fronted part be picked up by re-settling after an undo', function () {
    // Settle before the $55 part gets flagged as tech-paid — only labor ($120) is credited.
    $job = dayJob($this->tech->id, 12000);
    $this->lifecycle->recordPayment($job, 30000, PaymentMethod::Cash, null, null, $this->dispatch);

    $page = Livewire::actingAs($this->dispatch)->test(TechPayments::class);
    $page->callTableAction('settle', $this->tech, data: [])->assertHasNoTableActionErrors();
    expect($this->tech->refresh()->cashOwedCents())->toBe(18000); // $300 - $120

    // The $55 part flag lands late.
    $job->update(['tech_parts_cost_cents' => 5500]);

    $page->callTableAction('undoSettle', $this->tech)->assertHasNoTableActionErrors();
    expect($this->tech->refresh()->cashOwedCents())->toBe(30000);

    // Re-settle: now credits labor + the part, correctly landing $55 lower.
    $page->callTableAction('settle', $this->tech, data: [])->assertHasNoTableActionErrors();
    expect($this->tech->refresh()->cashOwedCents())->toBe(12500); // $300 - $120 - $55
});

it('refuses to undo a settlement once the balance has moved since', function () {
    $job = dayJob($this->tech->id, 12000);

    $page = Livewire::actingAs($this->dispatch)->test(TechPayments::class);
    $page->callTableAction('settle', $this->tech, data: ['method' => 'zelle'])->assertHasNoTableActionErrors();

    // Something else touches the balance after settling (e.g. a later cash job).
    $this->tech->update(['cash_balance_cents' => 5000]);

    expect(fn () => $this->lifecycle->undoSettleTechDay(
        TechSettlement::where('technician_id', $this->tech->id)->where('type', 'eod_payout')->first(),
        $this->dispatch,
    ))->toThrow(RuntimeException::class);
});

it('blocks an employee without the payout permission', function () {
    $nobody = Employee::create([
        'name' => 'Nobody', 'email' => 'n@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);

    $this->actingAs($nobody)
        ->get(TechPayments::getUrl())
        ->assertForbidden();
});
