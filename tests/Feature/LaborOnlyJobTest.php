<?php

use App\Console\Commands\SweepJobIssues;
use App\Enums\IssueType;
use App\Enums\PartsSupplier;
use App\Filament\Pages\Intake;
use App\Filament\Resources\Jobs\Pages\ViewJob;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Issue;
use App\Models\Job;
use App\Models\Warehouse;
use App\Services\JobLifecycle;
use App\Services\Telegram\OpsDigest;
use App\Services\Telegram\SameDayPartsUnorderedAlert;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    Carbon::setTestNow(Carbon::parse('2026-07-20 09:00', 'America/Los_Angeles'));

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->rep = Employee::create([
        'name' => 'Rodolfo', 'email' => 'rodolfo@test.local', 'active' => true, 'job_functions' => ['sales'],
    ]);

    $this->tech = Employee::create([
        'name' => 'Tech', 'email' => 'tech@test.local', 'active' => true,
        'job_functions' => ['technician'], 'phone' => '+18185550000',
    ]);

    $this->actingAs($this->actor);
    $this->lifecycle = app(JobLifecycle::class);
});

afterEach(fn () => Carbon::setTestNow());

/** A labor-only job (customer's own glass), optionally already scheduled. */
function laborOnlyJob($test, ?string $scheduledLocal = null): Job
{
    $job = $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Alan',
        'quote_amount_cents' => 20000,
        'labor_only' => true,
    ], $test->actor);

    if ($scheduledLocal !== null) {
        $test->lifecycle->schedule(
            $job,
            Carbon::parse($scheduledLocal, 'America/Los_Angeles'),
            $test->actor,
            technicianId: $test->tech->id,
        );
    }

    return $job->refresh();
}

// ---- Intake ----

it('intake stores the labor-only flag and no parts', function () {
    Livewire::test(Intake::class)
        ->set('data.entity_id', Entity::where('code', 'LA')->value('id'))
        ->set('data.booking_salesperson_id', $this->rep->id)
        ->set('data.phone_e164', '+18185551234')
        ->set('data.first_name', 'Alan')
        ->set('data.service_address', '123 Main St')
        ->set('data.labor_only', true)
        ->set('data.quote', 200)
        ->call('create')
        ->assertHasNoErrors();

    $job = Job::latest('id')->first();

    expect($job->labor_only)->toBeTrue()
        ->and($job->isLaborOnly())->toBeTrue()
        ->and($job->parts_json)->toBeNull()
        ->and($job->part_number)->toBeNull()
        ->and($job->part_cost_cents)->toBeNull()
        ->and($job->quote_amount_cents)->toBe(20000);
});

it('labor only wins over anything left in the parts repeater', function () {
    Livewire::test(Intake::class)
        ->set('data.entity_id', Entity::where('code', 'LA')->value('id'))
        ->set('data.booking_salesperson_id', $this->rep->id)
        ->set('data.phone_e164', '+18185551234')
        ->set('data.first_name', 'Alan')
        ->set('data.service_address', '123 Main St')
        ->set('data.parts', [['label' => 'DW2853GTY', 'cost' => 350]])
        ->set('data.labor_only', true)
        ->call('create')
        ->assertHasNoErrors();

    $job = Job::latest('id')->first();

    expect($job->labor_only)->toBeTrue()
        ->and($job->parts_json)->toBeNull()
        ->and($job->part_cost_cents)->toBeNull();
});

it('a normal intake job is not labor only', function () {
    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185559999',
        'first_name' => 'Beth',
    ], $this->actor);

    expect($job->labor_only)->toBeFalse()
        ->and($job->isLaborOnly())->toBeFalse();
});

// ---- Scheduling needs no warehouse ----

it('a labor-only job schedules with no warehouse and no order prompt', function () {
    $job = laborOnlyJob($this);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('schedule', data: [
            'scheduled_date' => '2026-07-22',
            'scheduled_time' => '10:00',
            'technician_id' => $this->tech->id,
        ])
        ->assertHasNoActionErrors()
        // A parts job would chain into the order modal here.
        ->assertActionNotMounted('orderParts');

    $job->refresh();

    expect($job->status->value)->toBe('scheduled')
        ->and($job->warehouse_id)->toBeNull()
        ->and($job->warehouse_other)->toBeNull();
});

it('a same-day labor-only job schedules without the order-the-part section', function () {
    $job = laborOnlyJob($this);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('schedule', data: [
            'scheduled_date' => '2026-07-20',
            'scheduled_time' => '14:00',
            'technician_id' => $this->tech->id,
        ])
        ->assertHasNoActionErrors();

    expect($job->refresh()->status->value)->toBe('scheduled');
});

it('a parts job can schedule without a warehouse (deferred to order time)', function () {
    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185559999', 'first_name' => 'Beth',
        'parts_json' => [['label' => 'DW2853GTY', 'cost_cents' => 35000]],
    ], $this->actor);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('schedule', data: [
            'scheduled_date' => '2026-07-22',
            'scheduled_time' => '10:00',
            'technician_id' => $this->tech->id,
        ])
        ->assertHasNoActionErrors();

    expect($job->fresh()->status->value)->toBe('scheduled');
});

// ---- Parts queues, badges and alerts skip it ----

it('a scheduled labor-only job never lands in the parts queues', function () {
    $laborOnly = laborOnlyJob($this, '2026-07-21 09:00');

    $parts = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185557777', 'first_name' => 'Carl',
        'parts_json' => [['label' => 'DW2853GTY', 'cost_cents' => 35000]],
    ], $this->actor);
    $warehouse = Warehouse::create(['name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014']);
    $this->lifecycle->schedule($parts, Carbon::parse('2026-07-21 09:00', 'America/Los_Angeles'),
        $this->actor, technicianId: $this->tech->id, warehouseId: $warehouse->id);

    expect(Job::partsToOrder()->pluck('id')->all())->toContain($parts->id)->not->toContain($laborOnly->id)
        ->and(Job::partsReadyToOrder()->pluck('id')->all())->not->toContain($laborOnly->id)
        ->and(Job::noWarehouse()->pluck('id')->all())->not->toContain($laborOnly->id);
});

it('a labor-only job carries no part open-task badges', function () {
    $job = laborOnlyJob($this, '2026-07-21 09:00');

    expect($job->openTaskTypes())->not->toContain('part_ordered')
        ->and($job->openTaskTypes())->not->toContain('part_paid');
});

it('a labor-only Other-supplier job never needs a prepay', function () {
    $job = laborOnlyJob($this, '2026-07-21 09:00');
    // Even with the "Other" shape forced on, there is no part of ours to prepay.
    $job->update(['warehouse_id' => null, 'warehouse_other' => 'Some dealer', 'parts_ordered_at' => now()]);

    expect($job->refresh()->needsPartsPrepaid())->toBeFalse()
        ->and(Job::prepayPending()->pluck('id')->all())->not->toContain($job->id);
});

it('the Order part action is hidden on a labor-only job', function () {
    $job = laborOnlyJob($this, '2026-07-21 09:00');

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->assertActionHidden('orderParts');
});

it('parts Telegram alerts stay quiet for a labor-only job', function () {
    laborOnlyJob($this, '2026-07-20 14:00'); // today

    expect(app(SameDayPartsUnorderedAlert::class)->build())->toBeNull()
        ->and(app(OpsDigest::class)->buildMessages('morning'))->toBeEmpty();
});

it('the Issues sweep raises no part-not-ordered issue for a labor-only job', function () {
    $job = laborOnlyJob($this, '2026-07-20 14:00'); // scheduled today

    $this->artisan(SweepJobIssues::class)->assertSuccessful();

    expect(Issue::where('type', IssueType::PartNotOrdered->value)
        ->where('subject_id', $job->id)->exists())->toBeFalse();
});

// ---- Money edits clear any part left behind ----

it('flipping a job to labor only clears its part cost so margin stops subtracting it', function () {
    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185558888', 'first_name' => 'Dana',
        'quote_amount_cents' => 40000,
        'parts_json' => [['label' => 'DW2853GTY', 'cost_cents' => 15000]],
        'part_cost_cents' => 15000,
    ], $this->actor);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction(TestAction::make('editMoney')->schemaComponent('money'), data: [
            'labor_only' => true,
            'quote_amount_cents' => 400,
            'tech_pay_amount_cents' => 100,
        ])
        ->assertHasNoActionErrors();

    $job->refresh();

    expect($job->labor_only)->toBeTrue()
        ->and($job->parts_json)->toBeNull()
        ->and($job->part_number)->toBeNull()
        ->and($job->part_cost_cents)->toBeNull()
        ->and($job->tech_parts_cost_cents)->toBeNull()
        // Quote 400 - tech pay 100, with no part cost dragging it down.
        ->and($job->gross_margin_cents)->toBe(30000);
});
