<?php

use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Filament\Pages\InsuranceJobs;
use App\Filament\Resources\Jobs\Pages\ViewJob;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Services\JobLifecycle;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->actingAs($this->actor);
    $this->lifecycle = app(JobLifecycle::class);
});

function insuranceIntake(): array
{
    return [
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185559876',
        'first_name' => 'Ivan',
        'quote_amount_cents' => 10000, // $100 deductible
        'is_insurance' => true,
        'intake_raw_text' => 'raw',
    ];
}

it('flags a job as insurance at intake', function () {
    $job = $this->lifecycle->createFromIntake(insuranceIntake(), $this->actor);

    expect($job->is_insurance)->toBeTrue()
        ->and($job->insurance_paid_at)->toBeNull()
        ->and($job->quote_amount_cents)->toBe(10000);
});

it('defaults non-insurance when the flag is omitted', function () {
    $data = insuranceIntake();
    unset($data['is_insurance']);

    $job = $this->lifecycle->createFromIntake($data, $this->actor);

    expect($job->is_insurance)->toBeFalse();
});

it('records the insurance carrier payment without gating completion on it', function () {
    $job = $this->lifecycle->createFromIntake(insuranceIntake(), $this->actor);

    $this->lifecycle->recordInsurancePayment($job, 40000, 'CLM-123', 'paid by check', $this->actor);
    $job->refresh();

    expect($job->isInsurancePaid())->toBeTrue()
        ->and($job->insurance_amount_cents)->toBe(40000)
        ->and($job->insurance_reference)->toBe('CLM-123')
        ->and($job->insurance_notes)->toBe('paid by check')
        ->and($job->insurance_paid_recorded_by)->toBe($this->actor->id)
        // Insurance payment is orthogonal — it must not complete the job on its own.
        ->and($job->status)->toBe(JobStatus::PendingSchedule);

    expect(Activity::where('subject_type', $job->getMorphClass())
        ->where('subject_id', $job->id)->pluck('event'))->toContain('insurance_paid');
});

it('the deductible (customer payment) is independent of the insurance payment', function () {
    $job = $this->lifecycle->createFromIntake(insuranceIntake(), $this->actor);
    $tech = Employee::create([
        'name' => 'Ins Tech', 'email' => 'ins-tech@test.local', 'can_login' => false,
        'active' => true, 'password' => bcrypt('x'), 'job_functions' => ['technician'],
    ]);
    $job->update(['technician_id' => $tech->id]);

    // Customer pays the deductible through the normal flow.
    $this->lifecycle->recordPayment($job, 10000, PaymentMethod::Cash, null, null, $this->actor);
    $job->refresh();

    expect($job->isPaid())->toBeTrue()
        ->and($job->amount_collected_cents)->toBe(10000)
        ->and($job->isInsurancePaid())->toBeFalse();
});

it('scopes insurance and insuranceUnpaid jobs', function () {
    $insured = $this->lifecycle->createFromIntake(insuranceIntake(), $this->actor);

    $plainData = insuranceIntake();
    $plainData['phone_e164'] = '+18185550000';
    $plainData['is_insurance'] = false;
    $this->lifecycle->createFromIntake($plainData, $this->actor);

    expect(Job::insurance()->count())->toBe(1)
        ->and(Job::insuranceUnpaid()->count())->toBe(1);

    $this->lifecycle->recordInsurancePayment($insured, 40000, null, null, $this->actor);

    expect(Job::insurance()->count())->toBe(1)
        ->and(Job::insuranceUnpaid()->count())->toBe(0);
});

it('tracks carrier, claim number and expected payout on the job', function () {
    $job = $this->lifecycle->createFromIntake(insuranceIntake(), $this->actor);

    $job->update([
        'insurance_carrier' => 'State Farm',
        'insurance_claim_number' => 'SF-88-1234',
        'insurance_expected_cents' => 40000,
    ]);
    $job->refresh();

    expect($job->insurance_carrier)->toBe('State Farm')
        ->and($job->insurance_claim_number)->toBe('SF-88-1234')
        ->and($job->insurance_expected_cents)->toBe(40000)
        // Nothing received yet — the whole expected payout is outstanding.
        ->and($job->insuranceOutstandingCents())->toBe(40000)
        ->and(Job::insuranceOutstanding()->pluck('id'))->toContain($job->id);
});

it('clears the outstanding balance once the carrier pays, whatever the amount', function () {
    $job = $this->lifecycle->createFromIntake(insuranceIntake(), $this->actor);
    $job->update(['insurance_expected_cents' => 40000]);

    // Carrier short-paid: once it's settled the actual payout is the truth, so
    // nothing stays "outstanding" — a short-pay is a dispute, not a receivable.
    $this->lifecycle->recordInsurancePayment($job->refresh(), 35000, 'CHK-9', null, $this->actor);
    $job->refresh();

    expect($job->insuranceOutstandingCents())->toBe(0)
        ->and(Job::insuranceOutstanding()->pluck('id'))->not->toContain($job->id);
});

it('never reports a negative outstanding balance, and ignores unquantified claims', function () {
    $overpaid = $this->lifecycle->createFromIntake(insuranceIntake(), $this->actor);
    $overpaid->update(['insurance_expected_cents' => 10000, 'insurance_amount_cents' => 25000]);

    $unquantified = $this->lifecycle->createFromIntake(
        array_merge(insuranceIntake(), ['phone_e164' => '+18185551111']),
        $this->actor,
    );

    expect($overpaid->refresh()->insuranceOutstandingCents())->toBe(0)
        ->and($unquantified->refresh()->insuranceOutstandingCents())->toBe(0)
        // Unpaid but unquantified: still an unpaid claim, just not a counted receivable.
        ->and(Job::insuranceUnpaid()->pluck('id'))->toContain($unquantified->id)
        ->and(Job::insuranceOutstanding()->pluck('id'))->not->toContain($unquantified->id);
});

it('a non-insurance job never carries an outstanding insurance balance', function () {
    $data = insuranceIntake();
    $data['is_insurance'] = false;
    $job = $this->lifecycle->createFromIntake($data, $this->actor);
    $job->update(['insurance_expected_cents' => 40000]);

    expect($job->refresh()->insuranceOutstandingCents())->toBe(0);
});

it('shows the claim details in the job view Insurance section, only on an insurance job', function () {
    $insured = $this->lifecycle->createFromIntake(insuranceIntake(), $this->actor);
    $insured->update([
        'insurance_carrier' => 'Progressive',
        'insurance_claim_number' => 'PG-4242',
        'insurance_expected_cents' => 40000,
    ]);

    Livewire::test(ViewJob::class, ['record' => $insured->refresh()->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Progressive')
        ->assertSee('PG-4242');

    $plainData = insuranceIntake();
    $plainData['phone_e164'] = '+18185552222';
    $plainData['is_insurance'] = false;
    $plain = $this->lifecycle->createFromIntake($plainData, $this->actor);

    Livewire::test(ViewJob::class, ['record' => $plain->getRouteKey()])
        ->assertSuccessful()
        ->assertDontSee('Claim number');
});

it('records the carrier payment from the Insurance Jobs page and totals what is outstanding', function () {
    $paidLater = $this->lifecycle->createFromIntake(insuranceIntake(), $this->actor);
    $paidLater->update(['insurance_expected_cents' => 40000]);

    $stillOpen = $this->lifecycle->createFromIntake(
        array_merge(insuranceIntake(), ['phone_e164' => '+18185553333']),
        $this->actor,
    );
    $stillOpen->update(['insurance_expected_cents' => 25000]);

    // $400 + $250 outstanding before either carrier pays.
    expect(app(InsuranceJobs::class)->getSubheading())->toContain('$650.00 outstanding');

    Livewire::test(InsuranceJobs::class)
        ->assertCanSeeTableRecords([$paidLater, $stillOpen])
        ->callTableAction('markInsurancePaid', $paidLater, data: [
            'amount' => '400',
            'insurance_carrier' => 'USAA',
            'insurance_claim_number' => 'US-1',
            'reference' => 'CHK-1',
        ])
        ->assertHasNoTableActionErrors();

    $paidLater->refresh();

    expect($paidLater->isInsurancePaid())->toBeTrue()
        ->and($paidLater->insurance_amount_cents)->toBe(40000)
        ->and($paidLater->insurance_carrier)->toBe('USAA')
        ->and($paidLater->insurance_claim_number)->toBe('US-1')
        ->and(app(InsuranceJobs::class)->getSubheading())->toContain('$250.00 outstanding');
});

it('fills in the carrier and claim number when they are first learned at payment time', function () {
    $job = $this->lifecycle->createFromIntake(insuranceIntake(), $this->actor);
    $job->update(['insurance_carrier' => 'GEICO']);

    // Claim # learned with the check; the carrier we already knew must survive
    // a null being passed rather than being blanked out.
    $this->lifecycle->recordInsurancePayment(
        $job->refresh(), 40000, 'CHK-77', null, $this->actor,
        carrier: null, claimNumber: 'GE-55-9',
    );
    $job->refresh();

    expect($job->insurance_carrier)->toBe('GEICO')
        ->and($job->insurance_claim_number)->toBe('GE-55-9');
});
