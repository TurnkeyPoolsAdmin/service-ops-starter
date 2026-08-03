<?php

use App\Enums\TimePreference;
use App\Filament\Resources\Jobs\Pages\ViewJob;
use App\Filament\Resources\Jobs\Schemas\JobForm;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Services\JobLifecycle;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'a@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['manager'],
    ])->assignRole('super_admin');
    $this->actingAs($this->admin);
});

function makeJob(): Job
{
    $entity = Entity::where('code', 'LA')->first();
    Customer::create(['phone_e164' => '+13235553658', 'first_name' => 'Ron']);

    return app(JobLifecycle::class)->createFromIntake([
        'entity_id' => $entity->id, 'phone_e164' => '+13235553658', 'first_name' => 'Ron',
        'vehicle_year' => '2020', 'vehicle_make' => 'Honda', 'vehicle_model' => 'Pilot',
        'parts_json' => [['label' => 'FW04239', 'cost_cents' => 15500]],
        'part_number' => 'FW04239', 'part_cost_cents' => 15500, 'quote_amount_cents' => 68200,
    ], test()->admin);
}

it('edits the intake note inline without a full edit page', function () {
    $job = makeJob();
    $job->update(['notes' => 'Park in the alley']);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction(TestAction::make('editNotes')->schemaComponent('notes'), data: ['notes' => 'Bring the tall ladder'])
        ->assertHasNoActionErrors();

    expect($job->refresh()->notes)->toBe('Bring the tall ladder');
});

// The notes section edits the INTAKE note only, and plenty of jobs never get
// one — so the header action is hidden rather than offering an empty field.
// Logged notes are an append-only trail edited from their own bubbles.
it('hides the intake-note edit action on a job that has no intake note', function () {
    $job = makeJob();

    expect($job->notes)->toBeNull();

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->assertActionDoesNotExist(TestAction::make('editNotes')->schemaComponent('notes'));
});

it('edits the money section inline, converting dollar inputs back to cents', function () {
    $job = makeJob();

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction(TestAction::make('editMoney')->schemaComponent('money'), data: [
            'quote_amount_cents' => 750,            // dollars in the form
            'tech_pay_amount_cents' => 120,
            'additional_labor_cents' => 30,
            'additional_labor_reason' => 'molding',
        ])
        ->assertHasNoActionErrors();

    $job->refresh();
    expect($job->quote_amount_cents)->toBe(75000)
        ->and($job->tech_pay_amount_cents)->toBe(12000)
        ->and($job->additional_labor_cents)->toBe(3000)
        ->and($job->additional_labor_reason)->toBe('molding');
});

it('edits the scheduling section inline, syncing the requested string', function () {
    $job = makeJob();

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction(TestAction::make('editScheduling')->schemaComponent('scheduling'), data: [
            'service_address' => '123 Main St',
            // Requested day is a date picker now — the effective string spells it out.
            'requested_day' => '2026-07-22',
            // The free-text time only applies (and only shows) when the
            // preference Select says they named a specific one.
            'requested_time_preference' => TimePreference::Specific->value,
            'requested_time' => '9am',
        ])
        ->assertHasNoActionErrors();

    $job->refresh();
    expect($job->service_address)->toBe('123 Main St')
        ->and($job->requested_day)->toBe('2026-07-22')
        ->and($job->requested_time_preference)->toBe(TimePreference::Specific)
        ->and($job->requested_daytime_raw)->toBe('Wednesday, July 22 9am');
});

it('spells a non-specific time preference into the requested string', function () {
    $job = makeJob();

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction(TestAction::make('editScheduling')->schemaComponent('scheduling'), data: [
            'service_address' => '123 Main St',
            'requested_day' => '2026-07-22',
            'requested_time_preference' => TimePreference::Morning->value,
        ])
        ->assertHasNoActionErrors();

    expect($job->refresh()->requested_daytime_raw)->toBe('Wednesday, July 22 Morning');
});

it('edits an after-a-time request and drops the hour when the preference moves on', function () {
    $job = makeJob();

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction(TestAction::make('editScheduling')->schemaComponent('scheduling'), data: [
            'service_address' => '123 Main St',
            'requested_day' => '2026-07-22',
            'requested_time_preference' => TimePreference::After->value,
            'requested_after_time' => '15:00',
        ])
        ->assertHasNoActionErrors();

    $job->refresh();
    expect($job->requested_time_preference)->toBe(TimePreference::After)
        ->and($job->requested_after_time)->toBe('15:00')
        ->and($job->requested_daytime_raw)->toBe('Wednesday, July 22 After 3pm');

    // The section re-opens on the stored preference (the column casts to the
    // enum, so the Select has to read a case as well as a string), and moving
    // off After clears the hour behind it.
    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction(TestAction::make('editScheduling')->schemaComponent('scheduling'), data: [
            'service_address' => '123 Main St',
            'requested_day' => '2026-07-22',
            'requested_time_preference' => TimePreference::Asap->value,
        ])
        ->assertHasNoActionErrors();

    $job->refresh();
    expect($job->requested_after_time)->toBeNull()
        ->and($job->requested_daytime_raw)->toBe('Wednesday, July 22 ASAP');
});

it('round-trips parts and requested-time transforms', function () {
    // to/from parts input (tech_paid flag round-trips)
    $rows = JobForm::toPartsInput(['parts_json' => [['label' => 'A', 'cost_cents' => 100, 'tech_paid' => true]]]);
    expect($rows)->toBe([['label' => 'A', 'cost_cents' => 100, 'tech_paid' => true]]);

    $cols = JobForm::fromPartsInput(['parts_input' => [
        ['label' => 'A', 'cost_cents' => 100, 'tech_paid' => true],   // tech fronted this
        ['label' => 'B', 'cost_cents' => 200, 'tech_paid' => false],  // we bought this
        ['label' => '', 'cost_cents' => null],                        // blank row dropped
    ]]);
    expect($cols['parts_json'])->toBe([
        ['label' => 'A', 'cost_cents' => 100, 'tech_paid' => true],
        ['label' => 'B', 'cost_cents' => 200],
    ])
        ->and($cols['part_number'])->toBe('A')
        ->and($cols['part_cost_cents'])->toBe(300)
        ->and($cols['tech_parts_cost_cents'])->toBe(100) // only the checked part
        ->and($cols)->not->toHaveKey('parts_input');

    // No checked parts → reimbursement is null (the common case).
    $none = JobForm::fromPartsInput(['parts_input' => [['label' => 'A', 'cost_cents' => 100]]]);
    expect($none['tech_parts_cost_cents'])->toBeNull();

    // requested string is day+time, with a picked date spelled out for the texts
    expect(JobForm::syncRequestedDaytime(['requested_day' => '2026-07-21', 'requested_time' => '2pm']))
        ->toMatchArray(['requested_daytime_raw' => 'Tuesday, July 21 2pm']);
    // legacy free-text days (pre-calendar jobs) pass straight through
    expect(JobForm::syncRequestedDaytime(['requested_day' => 'Tue', 'requested_time' => '2pm']))
        ->toMatchArray(['requested_daytime_raw' => 'Tue 2pm']);
    expect(JobForm::syncRequestedDaytime(['requested_day' => '', 'requested_time' => '']))
        ->toMatchArray(['requested_daytime_raw' => null]);
});
