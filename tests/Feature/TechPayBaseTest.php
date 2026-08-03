<?php

use App\Filament\Pages\TechPayments;
use App\Filament\Resources\Jobs\Pages\EditJob;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Services\JobLifecycle;
use App\Support\Timezone;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'a@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['manager'],
    ])->assignRole('super_admin');

    $this->cheapTech = Employee::create([
        'name' => 'Cheap Tech', 'email' => 'c@test.local', 'can_login' => false, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['technician'], 'fixed_cost_cents' => 15000,
    ]);

    $this->pricyTech = Employee::create([
        'name' => 'Pricy Tech', 'email' => 'p@test.local', 'can_login' => false, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['technician'], 'fixed_cost_cents' => 25000,
    ]);

    $this->lifecycle = app(JobLifecycle::class);

    $this->job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Al', 'quote_amount_cents' => 55000,
        'service_address' => '1 Main St', 'city' => 'Los Angeles',
    ], $this->admin);

    $this->actingAs($this->admin);
});

afterEach(fn () => Carbon::setTestNow());

it('fixes tech pay at the assigned tech\'s base cost', function () {
    $this->lifecycle->assignTechnician($this->job, $this->cheapTech->id, $this->admin);

    expect($this->job->fresh()->tech_pay_amount_cents)->toBe(15000);
});

it('rewrites the base when the job is swapped to a tech who costs more', function () {
    $this->lifecycle->assignTechnician($this->job, $this->cheapTech->id, $this->admin);
    $this->lifecycle->assignTechnician($this->job, $this->pricyTech->id, $this->admin);

    // The old tech's $150 must not linger on a job the $250 tech now runs.
    expect($this->job->fresh()->tech_pay_amount_cents)->toBe(25000);
});

it('re-syncs the base pay when the technician is changed by a plain update', function () {
    $this->lifecycle->assignTechnician($this->job, $this->cheapTech->id, $this->admin);

    // The job form's Scheduling section saves straight to the model — it never
    // goes through JobLifecycle, so the model hook is what keeps pay honest.
    $this->job->update(['technician_id' => $this->pricyTech->id]);

    expect($this->job->fresh()->tech_pay_amount_cents)->toBe(25000);
});

it('blanks the base pay when the tech has no fixed cost on file', function () {
    $noCost = Employee::create([
        'name' => 'New Tech', 'email' => 'n@test.local', 'can_login' => false, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['technician'],
    ]);

    $this->lifecycle->assignTechnician($this->job, $this->cheapTech->id, $this->admin);
    $this->lifecycle->assignTechnician($this->job, $noCost->id, $this->admin);

    // Better a visible gap than the previous tech's number on the new tech's job.
    expect($this->job->fresh()->tech_pay_amount_cents)->toBeNull();
});

it('never rewrites the pay once the payout is recorded', function () {
    $this->lifecycle->assignTechnician($this->job, $this->cheapTech->id, $this->admin);
    $this->job->update(['tech_paid_at' => now(), 'tech_pay_amount_cents' => 18000]);

    $this->job->update(['technician_id' => $this->pricyTech->id]);

    expect($this->job->fresh()->tech_pay_amount_cents)->toBe(18000);
});

it('totals base pay plus additional labor', function () {
    $this->lifecycle->assignTechnician($this->job, $this->cheapTech->id, $this->admin);
    $this->job->update(['additional_labor_cents' => 5000, 'additional_labor_reason' => 'distance']);

    expect($this->job->fresh()->expected_tech_pay_cents)->toBe(20000);
});

it('renders tech pay read-only on the job form', function () {
    $this->lifecycle->assignTechnician($this->job, $this->cheapTech->id, $this->admin);

    Livewire::test(EditJob::class, ['record' => $this->job->getRouteKey()])
        ->assertSuccessful()
        ->assertFormFieldIsDisabled('tech_pay_amount_cents')
        ->assertFormSet(['tech_pay_amount_cents' => 150.0]);
});

it('requires a reason once additional labor is entered', function () {
    $this->lifecycle->assignTechnician($this->job, $this->cheapTech->id, $this->admin);

    Livewire::test(EditJob::class, ['record' => $this->job->getRouteKey()])
        ->fillForm(['additional_labor_cents' => 50, 'additional_labor_reason' => null])
        ->call('save')
        ->assertHasFormErrors(['additional_labor_reason']);

    expect($this->job->fresh()->additional_labor_cents)->toBeNull();
});

it('saves additional labor once a reason is given, and keeps the base intact', function () {
    $this->lifecycle->assignTechnician($this->job, $this->cheapTech->id, $this->admin);

    Livewire::test(EditJob::class, ['record' => $this->job->getRouteKey()])
        ->fillForm(['additional_labor_cents' => 50, 'additional_labor_reason' => '2_man_job'])
        ->call('save')
        ->assertHasNoFormErrors();

    $job = $this->job->fresh();

    // The disabled field still dehydrates, so the base survives the save.
    expect($job->tech_pay_amount_cents)->toBe(15000)
        ->and($job->additional_labor_cents)->toBe(5000)
        ->and($job->expected_tech_pay_cents)->toBe(20000);
});

it('splits base and additional labor in the End-of-Day breakdown', function () {
    Carbon::setTestNow(Timezone::now()->setTime(14, 0));

    $this->lifecycle->assignTechnician($this->job, $this->cheapTech->id, $this->admin);
    $this->job->update([
        'status' => 'work_completed',
        'work_completed_at' => now(),
        'additional_labor_cents' => 5000,
        'additional_labor_reason' => 'molding',
    ]);

    $tech = Employee::whereKey($this->cheapTech->id)->first();
    $today = Timezone::now()->format('Y-m-d');

    $page = Livewire::test(TechPayments::class, ['date' => $today])
        ->mountTableAction('breakdown', $this->cheapTech)
        ->assertHasNoTableActionErrors();

    // The modal body is built by a private method; read it directly rather than
    // scraping the lazily-rendered modal markup.
    $html = (string) Closure::bind(
        fn () => $this->breakdownHtml($tech),
        $page->instance(),
        TechPayments::class,
    )();

    // The day's total is the full $200, and the breakdown says where the $50 came from.
    expect($html)->toContain('Base')
        ->toContain('$150.00')
        ->toContain('Additional labor (Molding)')
        ->toContain('$50.00')
        ->toContain('$200.00');
});

it('keeps the day total at base plus additional labor', function () {
    Carbon::setTestNow(Timezone::now()->setTime(14, 0));

    $this->lifecycle->assignTechnician($this->job, $this->cheapTech->id, $this->admin);
    $this->job->update([
        'status' => 'work_completed',
        'work_completed_at' => now(),
        'additional_labor_cents' => 5000,
        'additional_labor_reason' => 'molding',
    ]);

    $owed = Job::whereKey($this->job->id)->first()->total_owed_to_tech_cents;

    expect($owed)->toBe(20000);
});
