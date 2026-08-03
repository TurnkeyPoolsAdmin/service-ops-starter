<?php

use App\Enums\PartsSupplier;
use App\Enums\TemplateKey;
use App\Filament\Pages\PartsToOrder;
use App\Filament\Resources\Jobs\Pages\ViewJob;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Holiday;
use App\Models\Job;
use App\Models\ScheduledMessage;
use App\Models\Warehouse;
use App\Services\JobLifecycle;
use App\Services\Parts\PartsOrderLinks;
use App\Services\Telegram\SameDayPartsUnorderedAlert;
use App\Support\BusinessDays;
use App\Support\Timezone;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->tech = Employee::create([
        'name' => 'Tech', 'email' => 'tech@test.local', 'active' => true,
        'job_functions' => ['technician'], 'phone' => '+18185550000',
    ]);

    $this->actingAs($this->actor);
    $this->lifecycle = app(JobLifecycle::class);
});

afterEach(fn () => Carbon::setTestNow());

function partsTestJob($test, string $scheduledLocal, ?Warehouse $warehouse = null, ?string $partLabel = 'FW05046GTYN windshield'): Job
{
    $job = $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Alan',
        'quote_amount_cents' => 45000,
        'parts_json' => $partLabel === null ? null : [['label' => $partLabel, 'cost_cents' => 10000]],
    ], $test->actor);

    $test->lifecycle->schedule(
        $job,
        Carbon::parse($scheduledLocal, 'America/Los_Angeles'),
        $test->actor,
        technicianId: $test->tech->id,
        warehouseId: $warehouse?->id,
    );

    return $job->refresh();
}

// ---- BusinessDays ----

it('Saturday scheduled for Monday is within the next business day', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-11 09:00', 'America/Los_Angeles')); // Saturday
    expect(BusinessDays::isWithinNextBusinessDay(Carbon::parse('2026-07-13', 'America/Los_Angeles')))->toBeTrue();
});

it('Monday scheduled for Wednesday is NOT within the next business day (no holiday)', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-06 09:00', 'America/Los_Angeles')); // Monday
    expect(BusinessDays::isWithinNextBusinessDay(Carbon::parse('2026-07-08', 'America/Los_Angeles')))->toBeFalse();
});

it('Monday scheduled for Wednesday IS within when Tuesday is a holiday', function () {
    Holiday::create(['date' => '2026-07-07']); // Tuesday
    Carbon::setTestNow(Carbon::parse('2026-07-06 09:00', 'America/Los_Angeles')); // Monday
    expect(BusinessDays::isWithinNextBusinessDay(Carbon::parse('2026-07-08', 'America/Los_Angeles')))->toBeTrue();
});

it('today or in the past is always within', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-09 09:00', 'America/Los_Angeles'));
    expect(BusinessDays::isWithinNextBusinessDay(Carbon::parse('2026-07-09', 'America/Los_Angeles')))->toBeTrue()
        ->and(BusinessDays::isWithinNextBusinessDay(Carbon::parse('2026-07-01', 'America/Los_Angeles')))->toBeTrue();
});

it('Sunday is skipped when computing the next business day', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-10 09:00', 'America/Los_Angeles')); // Friday
    expect(BusinessDays::nextBusinessDay(Timezone::now())->format('Y-m-d'))->toBe('2026-07-11'); // Saturday, not Sunday
});

// ---- PartsOrderLinks ----

it('builds the exact Mygrant URL with the warehouse code', function () {
    $warehouse = Warehouse::create(['name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014']);
    $job = partsTestJob($this, '2026-07-09 09:00', $warehouse);

    $links = app(PartsOrderLinks::class)->linksForJob($job);

    expect($links[0]['url'])->toBe('https://mygrantglass.com/pages/search.aspx?q=FW05046GTYN&sc=B014&do=Search')
        ->and($links[0]['note'])->toBeNull();
});

it('Mygrant without a code returns a note, no URL', function () {
    $warehouse = Warehouse::create(['name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value]);
    $job = partsTestJob($this, '2026-07-09 09:00', $warehouse);

    $links = app(PartsOrderLinks::class)->linksForJob($job);

    expect($links[0]['url'])->toBeNull()
        ->and($links[0]['note'])->toContain('Mygrant warehouse code');
});

it('IGC strips the trailing letter suffix', function () {
    $warehouse = Warehouse::create(['name' => 'IGC', 'supplier' => PartsSupplier::Igc->value]);
    $job = partsTestJob($this, '2026-07-09 09:00', $warehouse);

    $links = app(PartsOrderLinks::class)->linksForJob($job);

    expect($links[0]['url'])->toBe('https://importglasscorp.com/glass/FW05046');
});

it('IGC leaves an already-bare part number alone', function () {
    $warehouse = Warehouse::create(['name' => 'IGC', 'supplier' => PartsSupplier::Igc->value]);
    $job = partsTestJob($this, '2026-07-09 09:00', $warehouse, 'FW05046');

    $links = app(PartsOrderLinks::class)->linksForJob($job);

    expect($links[0]['url'])->toBe('https://importglasscorp.com/glass/FW05046');
});

it('an unparseable IGC part number returns a note instead of a URL', function () {
    $warehouse = Warehouse::create(['name' => 'IGC', 'supplier' => PartsSupplier::Igc->value]);
    $job = partsTestJob($this, '2026-07-09 09:00', $warehouse, '###');

    $links = app(PartsOrderLinks::class)->linksForJob($job);

    expect($links[0]['url'])->toBeNull()
        ->and($links[0]['note'])->toContain("Couldn't parse");
});

it('no warehouse (dealer/custom) returns the please-order note', function () {
    $job = partsTestJob($this, '2026-07-09 09:00', null);

    $links = app(PartsOrderLinks::class)->linksForJob($job);

    expect($links[0]['url'])->toBeNull()
        ->and($links[0]['note'])->toContain('dealer / custom supplier');
});

it('a label with trailing description text uses only the first token', function () {
    $warehouse = Warehouse::create(['name' => 'IGC', 'supplier' => PartsSupplier::Igc->value]);
    $job = partsTestJob($this, '2026-07-09 09:00', $warehouse, 'FW05046GTYN front windshield laminated');

    $links = app(PartsOrderLinks::class)->linksForJob($job);

    expect($links[0]['url'])->toBe('https://importglasscorp.com/glass/FW05046');
});

it('no part number on the job returns a note', function () {
    $job = partsTestJob($this, '2026-07-09 09:00', null, null);

    $links = app(PartsOrderLinks::class)->linksForJob($job);

    expect($links[0]['url'])->toBeNull()
        ->and($links[0]['note'])->toBe('No part number on the job');
});

// ---- Scopes ----

it('partsToOrder excludes unscheduled, canceled/lost, and already-ordered jobs', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-09 09:00', 'America/Los_Angeles'));

    $scheduled = partsTestJob($this, '2026-07-20 09:00');
    $ordered = partsTestJob($this, '2026-07-20 09:00');
    $this->lifecycle->markPartsOrdered($ordered, $this->actor);

    $unscheduled = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185559999',
        'first_name' => 'Beth',
    ], $this->actor);

    $lost = partsTestJob($this, '2026-07-20 09:00');
    $this->lifecycle->markLost($lost, 'canceled', $this->actor);

    $ids = Job::partsToOrder()->pluck('id')->all();

    expect($ids)->toContain($scheduled->id)
        ->not->toContain($ordered->id)
        ->not->toContain($unscheduled->id)
        ->not->toContain($lost->id);
});

it('partsReadyToOrder splits jobs across the business-day cutoff, including a holiday', function () {
    Holiday::create(['date' => '2026-07-07']); // Tuesday
    Carbon::setTestNow(Carbon::parse('2026-07-06 09:00', 'America/Los_Angeles')); // Monday; cutoff = Wed 7/8

    $ready = partsTestJob($this, '2026-07-08 09:00'); // exactly the cutoff
    $ahead = partsTestJob($this, '2026-07-09 09:00'); // one day past the cutoff

    $readyIds = Job::partsReadyToOrder()->pluck('id')->all();

    expect($readyIds)->toContain($ready->id)
        ->not->toContain($ahead->id);
});

// ---- markPartsOrdered ----

it('markPartsOrdered stamps the job, logs activity, and is idempotent', function () {
    $job = partsTestJob($this, '2026-07-09 09:00');

    $this->lifecycle->markPartsOrdered($job, $this->actor);
    $job->refresh();

    expect($job->parts_ordered_at)->not->toBeNull()
        ->and($job->parts_ordered_by)->toBe($this->actor->id)
        ->and(Activity::where('subject_id', $job->id)->where('event', 'parts_ordered')->exists())->toBeTrue();

    $firstStamp = $job->parts_ordered_at;
    $this->lifecycle->markPartsOrdered($job, $this->actor);
    expect($job->refresh()->parts_ordered_at->equalTo($firstStamp))->toBeTrue();
});

// ---- undoPartsOrdered ----

it('undoPartsOrdered clears the stamp, logs activity, and is idempotent', function () {
    $job = partsTestJob($this, '2026-07-09 09:00');
    $this->lifecycle->markPartsOrdered($job, $this->actor);

    $this->lifecycle->undoPartsOrdered($job->refresh(), $this->actor, 'marked by mistake');
    $job->refresh();

    expect($job->parts_ordered_at)->toBeNull()
        ->and($job->parts_ordered_by)->toBeNull()
        ->and(Activity::where('subject_id', $job->id)->where('event', 'parts_order_undone')->exists())->toBeTrue();

    // A second call on an already-unordered job writes nothing.
    $this->lifecycle->undoPartsOrdered($job, $this->actor);
    expect(Activity::where('subject_id', $job->id)->where('event', 'parts_order_undone')->count())->toBe(1);
});

it('undoPartsOrdered puts the job back on Parts to Order', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-08 09:00', 'America/Los_Angeles'));
    $job = partsTestJob($this, '2026-07-09 09:00');

    $this->lifecycle->markPartsOrdered($job, $this->actor);
    expect(Job::partsToOrder()->pluck('id')->all())->not->toContain($job->id);

    $this->lifecycle->undoPartsOrdered($job->refresh(), $this->actor);
    expect(Job::partsToOrder()->pluck('id')->all())->toContain($job->id);
});

it('undoPartsOrdered drops the prepay stamp with the order', function () {
    $job = otherWarehouseJob($this, '2026-07-20 09:00');
    $this->lifecycle->markPartsOrdered($job, $this->actor);
    $this->lifecycle->markPartsPaid($job->refresh(), $this->actor);

    $this->lifecycle->undoPartsOrdered($job->refresh(), $this->actor);
    $job->refresh();

    expect($job->isPartsOrdered())->toBeFalse()
        ->and($job->isPartsPaid())->toBeFalse()
        ->and(Job::prepayPending()->pluck('id')->all())->not->toContain($job->id);
});

it('the undo-part-ordered action is only offered once the part is stamped ordered', function () {
    $job = partsTestJob($this, '2026-07-09 09:00');

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->assertActionHidden('undoPartsOrdered');

    $this->lifecycle->markPartsOrdered($job, $this->actor);

    Livewire::test(ViewJob::class, ['record' => $job->refresh()->getRouteKey()])
        ->callAction('undoPartsOrdered', ['reason' => 'wrong job']);

    expect($job->refresh()->isPartsOrdered())->toBeFalse();
});

it('rescheduling an already-ordered job keeps the ordered stamp', function () {
    $job = partsTestJob($this, '2026-07-09 09:00');
    $this->lifecycle->markPartsOrdered($job, $this->actor);

    $this->lifecycle->schedule($job->refresh(), Carbon::parse('2026-07-15 10:00', 'America/Los_Angeles'), $this->actor);

    expect($job->refresh()->parts_ordered_at)->not->toBeNull();
});

// ---- Order-part action flow ----

it('the order-parts action requires a warehouse for a warehouse-less job, then shows links', function () {
    $warehouse = Warehouse::create(['name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014']);
    $job = partsTestJob($this, '2026-07-09 09:00', null);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('orderParts', ['warehouse_id' => $warehouse->id]);

    expect($job->refresh()->warehouse_id)->toBe($warehouse->id)
        ->and($job->parts_ordered_at)->not->toBeNull();
});

it('the order-parts action on a job that already has a warehouse just marks it ordered', function () {
    $warehouse = Warehouse::create(['name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014']);
    $job = partsTestJob($this, '2026-07-09 09:00', $warehouse);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('orderParts');

    expect($job->refresh()->parts_ordered_at)->not->toBeNull();
});

// ---- Rev 2: warehouse optional on schedule (not required — see JobActions::scheduleFields) ----

it('scheduling without a warehouse or warehouse_other is accepted', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-06 09:00', 'America/Los_Angeles'));
    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Alan',
    ], $this->actor);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('schedule', data: [
            'scheduled_date' => '2026-07-20',
            'scheduled_time' => '10:00',
            'technician_id' => $this->tech->id,
        ])
        ->assertHasNoActionErrors();

    expect($job->fresh()->status->value)->toBe('scheduled');
});

it('scheduling with the "Other" warehouse + free text is accepted', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-06 09:00', 'America/Los_Angeles'));
    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Alan',
    ], $this->actor);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('schedule', data: [
            'scheduled_date' => '2026-07-20',
            'scheduled_time' => '10:00',
            'technician_id' => $this->tech->id,
            'warehouse_id' => 'other',
            'warehouse_other' => 'Local dealer',
        ])
        ->assertHasNoActionErrors();

    $fresh = $job->fresh();
    expect($fresh->status->value)->toBe('scheduled')
        ->and($fresh->warehouse_id)->toBeNull()          // "other" saves no warehouse_id
        ->and($fresh->warehouse_other)->toBe('Local dealer');
});

it('scheduling with "Other" selected but no free text fails validation', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-06 09:00', 'America/Los_Angeles'));
    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Alan',
    ], $this->actor);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('schedule', data: [
            'scheduled_date' => '2026-07-20',
            'scheduled_time' => '10:00',
            'technician_id' => $this->tech->id,
            'warehouse_id' => 'other',
        ])
        ->assertHasActionErrors(['warehouse_other']);

    expect($job->fresh()->status->value)->toBe('pending_schedule');
});

// ---- Rev 3: same-day ordering is a warning, not a block (2026-07-23) ----

it('scheduling for today without confirming the order still schedules the job (non-blocking warning)', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-09 08:00', 'America/Los_Angeles'));
    $warehouse = Warehouse::create(['name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014']);
    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Alan',
        'quote_amount_cents' => 45000,
        'parts_json' => [['label' => 'FW05046GTYN windshield', 'cost_cents' => 10000]],
    ], $this->actor);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('schedule', data: [
            'scheduled_date' => '2026-07-09',
            'scheduled_time' => '14:00',
            'technician_id' => $this->tech->id,
            'warehouse_id' => $warehouse->id,
        ])
        ->assertHasNoActionErrors();

    $job->refresh();
    expect($job->status->value)->toBe('scheduled')
        ->and($job->parts_ordered_at)->toBeNull()
        ->and(ScheduledMessage::where('job_id', $job->id)
            ->where('template_key', TemplateKey::TimeConfirmation->value)->exists())->toBeTrue();
});

it('scheduling for today with the confirm checkbox ticked requires and stores the part cost', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-09 08:00', 'America/Los_Angeles'));
    $warehouse = Warehouse::create(['name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014']);
    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Alan',
        'quote_amount_cents' => 45000,
        'parts_json' => [['label' => 'FW05046GTYN windshield', 'cost_cents' => 10000]],
    ], $this->actor);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('schedule', data: [
            'scheduled_date' => '2026-07-09',
            'scheduled_time' => '14:00',
            'technician_id' => $this->tech->id,
            'warehouse_id' => $warehouse->id,
            'parts_ordered_confirm' => true,
            'parts_cost' => [['label' => 'FW05046GTYN windshield', 'cost' => '150.00']],
        ])
        ->assertHasNoActionErrors();

    $job->refresh();
    expect($job->status->value)->toBe('scheduled')
        ->and($job->parts_ordered_at)->not->toBeNull()
        ->and($job->part_cost_cents)->toBe(15000)
        ->and(ScheduledMessage::where('job_id', $job->id)
            ->where('template_key', TemplateKey::TimeConfirmation->value)->exists())->toBeTrue();
});

it('ticking the same-day confirm checkbox without a part cost fails validation (job still not scheduled)', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-09 08:00', 'America/Los_Angeles'));
    $warehouse = Warehouse::create(['name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014']);
    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Alan',
        'quote_amount_cents' => 45000,
        'parts_json' => [['label' => 'FW05046GTYN windshield', 'cost_cents' => 10000]],
    ], $this->actor);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('schedule', data: [
            'scheduled_date' => '2026-07-09',
            'scheduled_time' => '14:00',
            'technician_id' => $this->tech->id,
            'warehouse_id' => $warehouse->id,
            'parts_ordered_confirm' => true,
            'parts_cost' => [['label' => 'FW05046GTYN windshield', 'cost' => null]],
        ])
        ->assertHasActionErrors();

    $fresh = $job->fresh();
    expect($fresh->status->value)->toBe('pending_schedule')
        ->and($fresh->parts_ordered_at)->toBeNull();
});

it('same-day reschedule of an already-ordered job needs no confirm and keeps the stamp', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-09 08:00', 'America/Los_Angeles'));
    $warehouse = Warehouse::create(['name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014']);
    $job = partsTestJob($this, '2026-07-09 09:00', $warehouse);
    $this->lifecycle->markPartsOrdered($job, $this->actor);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('reschedule', data: [
            'scheduled_date' => '2026-07-09',
            'scheduled_time' => '16:00',
            'technician_id' => $this->tech->id,
            'warehouse_id' => $warehouse->id,
            'notify_customer' => false,
        ])
        ->assertHasNoActionErrors();

    expect($job->refresh()->parts_ordered_at)->not->toBeNull();
});

// ---- Rev 2: prompt after scheduling for ALL non-same-day jobs ----

it('scheduling for tomorrow chains straight into the order-parts modal', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-06 09:00', 'America/Los_Angeles')); // Monday
    $warehouse = Warehouse::create(['name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014']);
    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Alan',
    ], $this->actor);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('schedule', data: [
            'scheduled_date' => '2026-07-07',
            'scheduled_time' => '10:00',
            'technician_id' => $this->tech->id,
            'warehouse_id' => $warehouse->id,
        ])
        ->assertHasNoActionErrors()
        ->assertActionMounted('orderParts');
});

it('scheduling further out than the next business day ALSO chains into the order-parts modal', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-06 09:00', 'America/Los_Angeles')); // Monday; cutoff = Wed 7/8
    $warehouse = Warehouse::create(['name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014']);
    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Alan',
    ], $this->actor);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('schedule', data: [
            'scheduled_date' => '2026-07-20', // well past the next-business-day cutoff
            'scheduled_time' => '10:00',
            'technician_id' => $this->tech->id,
            'warehouse_id' => $warehouse->id,
        ])
        ->assertHasNoActionErrors()
        ->assertActionMounted('orderParts');
});

// ---- Parts prepaid ("Other" supplier) ----

/** Schedule a job against the free-text "Other" warehouse (no warehouse_id). */
function otherWarehouseJob($test, string $scheduledLocal, string $supplier = 'Pacific BMW'): Job
{
    $job = $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Alan',
        'quote_amount_cents' => 45000,
        'parts_json' => [['label' => 'FW05046GTYN windshield', 'cost_cents' => 10000]],
    ], $test->actor);

    $test->lifecycle->schedule(
        $job,
        Carbon::parse($scheduledLocal, 'America/Los_Angeles'),
        $test->actor,
        technicianId: $test->tech->id,
        warehouseOther: $supplier,
    );

    return $job->refresh();
}

it('needsPartsPrepaid is true only for unpaid Other-supplier jobs', function () {
    $warehouse = Warehouse::create(['name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014']);
    $ourWarehouse = partsTestJob($this, '2026-07-20 09:00', $warehouse);
    $other = otherWarehouseJob($this, '2026-07-20 09:00');

    expect($ourWarehouse->needsPartsPrepaid())->toBeFalse()
        ->and($other->usesOtherWarehouse())->toBeTrue()
        ->and($other->needsPartsPrepaid())->toBeTrue();

    $this->lifecycle->markPartsPaid($other, $this->actor);
    expect($other->refresh()->needsPartsPrepaid())->toBeFalse();
});

it('markPartsPaid stamps the job, logs activity, and is idempotent', function () {
    $job = otherWarehouseJob($this, '2026-07-20 09:00');

    $this->lifecycle->markPartsPaid($job, $this->actor);
    $job->refresh();

    expect($job->parts_paid_at)->not->toBeNull()
        ->and($job->parts_paid_by)->toBe($this->actor->id)
        ->and(Activity::where('subject_id', $job->id)->where('event', 'parts_paid')->exists())->toBeTrue();

    $firstStamp = $job->parts_paid_at;
    $this->lifecycle->markPartsPaid($job, $this->actor);
    expect($job->refresh()->parts_paid_at->equalTo($firstStamp))->toBeTrue();
});

it('prepayPending lists Other-supplier ordered-but-unpaid jobs only', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-13 09:00', 'America/Los_Angeles'));
    $warehouse = Warehouse::create(['name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014']);

    // Other supplier, ordered, unpaid → on the list.
    $pending = otherWarehouseJob($this, '2026-07-20 09:00');
    $this->lifecycle->markPartsOrdered($pending, $this->actor);

    // Other supplier, ordered AND paid → off the list.
    $paid = otherWarehouseJob($this, '2026-07-20 09:00');
    $this->lifecycle->markPartsOrdered($paid, $this->actor);
    $this->lifecycle->markPartsPaid($paid->refresh(), $this->actor);

    // Other supplier but not yet ordered → off (nothing prepaid until ordered).
    $notOrdered = otherWarehouseJob($this, '2026-07-20 09:00');

    // Our own warehouse → never needs prepay.
    $ours = partsTestJob($this, '2026-07-20 09:00', $warehouse);
    $this->lifecycle->markPartsOrdered($ours, $this->actor);

    $ids = Job::prepayPending()->pluck('id')->all();

    expect($ids)->toContain($pending->id)
        ->not->toContain($paid->id)
        ->not->toContain($notOrdered->id)
        ->not->toContain($ours->id);
});

it('the order-parts action can prepay an Other-supplier part in one step', function () {
    $job = otherWarehouseJob($this, '2026-07-20 09:00');

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('orderParts', ['parts_paid_confirm' => true]);

    $job->refresh();
    expect($job->isPartsOrdered())->toBeTrue()
        ->and($job->isPartsPaid())->toBeTrue();
});

it('captures the part cost when the part is ordered (intake leaves it blank)', function () {
    $job = otherWarehouseJob($this, '2026-07-20 09:00');
    // Intake no longer asks for a cost, so the job starts without one.
    $job->update(['parts_json' => [['label' => 'FW05046GTYN windshield', 'cost_cents' => null]], 'part_cost_cents' => null]);

    $component = Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->mountAction('orderParts');

    // Repeater rows are keyed by uuid — type the cost into the prefilled row.
    $rowKey = array_key_first($component->get('mountedActions.0.data.parts_cost'));

    $component
        ->setActionData(['parts_cost' => [$rowKey => ['label' => 'FW05046GTYN windshield', 'cost' => '212.50']]])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $job->refresh();
    expect($job->isPartsOrdered())->toBeTrue()
        ->and($job->part_cost_cents)->toBe(21250)
        ->and($job->parts_json[0]['cost_cents'])->toBe(21250);
});

it('requires a cost for a named part when marking it ordered', function () {
    $job = otherWarehouseJob($this, '2026-07-20 09:00');

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('orderParts', [
            'parts_cost' => [['label' => 'FW05046GTYN windshield', 'cost' => null]],
        ])
        ->assertHasActionErrors();

    expect($job->refresh()->isPartsOrdered())->toBeFalse();
});

it('the markPartsPaid action stamps prepay on an ordered Other-supplier job', function () {
    $job = otherWarehouseJob($this, '2026-07-20 09:00');
    $this->lifecycle->markPartsOrdered($job, $this->actor);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('markPartsPaid');

    expect($job->refresh()->isPartsPaid())->toBeTrue();
});

it('cancelAppointment un-stamps a prepaid Other part too', function () {
    $job = otherWarehouseJob($this, '2026-07-20 09:00');
    $this->lifecycle->markPartsOrdered($job, $this->actor);
    $this->lifecycle->markPartsPaid($job->refresh(), $this->actor);

    $this->lifecycle->cancelAppointment($job->refresh(), null, $this->actor);

    expect($job->refresh()->isPartsPaid())->toBeFalse()
        ->and($job->isPartsOrdered())->toBeFalse();
});

// ---- Warehouse delete detaches jobs (no FK error) ----

it('deleting a warehouse in use detaches its jobs, preserving the name', function () {
    $warehouse = Warehouse::create(['name' => 'Pacific BMW', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014']);
    $job = partsTestJob($this, '2026-07-20 09:00', $warehouse);
    expect($job->warehouse_id)->toBe($warehouse->id);

    $warehouse->delete();

    $job->refresh();
    expect(Warehouse::find($warehouse->id))->toBeNull()
        ->and($job->warehouse_id)->toBeNull()
        ->and($job->warehouse_other)->toBe('Pacific BMW');
});

// ---- Same-day parts-unordered Telegram nag (2026-07-23) ----

it('the same-day parts-unordered alert lists today\'s unordered jobs and clears once ordered', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-20 08:00', 'America/Los_Angeles'));
    $job = partsTestJob($this, '2026-07-20 14:00', Warehouse::create([
        'name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014',
    ]));

    $body = app(SameDayPartsUnorderedAlert::class)->build();
    expect($body)->not->toBeNull()
        ->and($body)->toContain('Alan');

    $this->lifecycle->markPartsOrdered($job, $this->actor);

    expect(app(SameDayPartsUnorderedAlert::class)->build())->toBeNull();

    Carbon::setTestNow();
});

it('the same-day parts-unordered alert ignores jobs scheduled for other days', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-20 08:00', 'America/Los_Angeles'));
    partsTestJob($this, '2026-07-21 14:00', Warehouse::create([
        'name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014',
    ]));

    expect(app(SameDayPartsUnorderedAlert::class)->build())->toBeNull();

    Carbon::setTestNow();
});

// ---- Rev 4: force a part cost whenever a job is marked ordered (2026-07-24) ----

it('the order-parts action rejects an all-blank submit', function () {
    $job = otherWarehouseJob($this, '2026-07-20 09:00');

    // Replace the (prefilled, valid) row in place with a blank one rather than
    // adding a second row alongside it, so this exercises the true all-blank case.
    $component = Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->mountAction('orderParts');

    $rowKey = array_key_first($component->get('mountedActions.0.data.parts_cost'));

    $component
        ->setActionData(['parts_cost' => [$rowKey => ['label' => null, 'cost' => null]]])
        ->callMountedAction()
        ->assertHasActionErrors();

    expect($job->refresh()->isPartsOrdered())->toBeFalse();
});

it('the Parts to Order quick-mark-ordered action also rejects an all-blank submit', function () {
    // Same-day so the job lands on the default "Ready to order" tab.
    Carbon::setTestNow(Carbon::parse('2026-07-20 08:00', 'America/Los_Angeles'));
    $warehouse = Warehouse::create(['name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014']);
    $job = partsTestJob($this, '2026-07-20 14:00', $warehouse);

    Livewire::test(PartsToOrder::class)
        ->callTableAction('quickMarkOrdered', $job, [
            'parts_cost' => [['label' => null, 'cost' => null]],
        ])
        ->assertHasTableActionErrors();

    expect($job->refresh()->isPartsOrdered())->toBeFalse();
});

it('applyPartsCost carries the tech-paid flag over by row position when ordering', function () {
    $warehouse = Warehouse::create(['name' => 'Mygrant SFV', 'supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014']);
    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Alan',
        'quote_amount_cents' => 45000,
        'parts_json' => [
            ['label' => 'Windshield', 'cost_cents' => null],
            ['label' => 'Molding', 'cost_cents' => null, 'tech_paid' => true],
        ],
    ], $this->actor);
    $this->lifecycle->schedule($job, Carbon::parse('2026-07-20 09:00', 'America/Los_Angeles'), $this->actor, technicianId: $this->tech->id, warehouseId: $warehouse->id);

    // Repeater rows are keyed by uuid — replace the two prefilled rows (in
    // order) rather than adding new indexed ones alongside them.
    $component = Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->mountAction('orderParts');

    $rowKeys = array_keys($component->get('mountedActions.0.data.parts_cost'));

    $component
        ->setActionData(['parts_cost' => [
            $rowKeys[0] => ['label' => 'Windshield', 'cost' => '150.00'],
            $rowKeys[1] => ['label' => 'Molding', 'cost' => '25.00'],
        ]])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $job->refresh();
    expect($job->parts_json[0]['tech_paid'] ?? false)->toBeFalse()
        ->and($job->parts_json[1]['tech_paid'])->toBeTrue()
        ->and($job->part_cost_cents)->toBe(17500)
        ->and($job->tech_parts_cost_cents)->toBe(2500);
});

// ---- Rev 4: a cost already on file can't be blanked once the part is ordered ----

it('an already-ordered job cannot have its part cost cleared on the Money section', function () {
    $job = partsTestJob($this, '2026-07-20 09:00');
    $this->lifecycle->markPartsOrdered($job, $this->actor);
    $originalCost = $job->part_cost_cents;

    // Replace the prefilled row in place (by its uuid key) rather than adding
    // a second one, so this exercises clearing the ONE row's cost.
    $component = Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->mountAction(TestAction::make('editMoney')->schemaComponent('money'));

    $rowKey = array_key_first($component->get('mountedActions.0.data.parts_input'));

    $component
        ->setActionData(['parts_input' => [$rowKey => ['label' => 'FW05046GTYN windshield', 'cost_cents' => null]]])
        ->callMountedAction()
        ->assertHasActionErrors();

    expect($job->refresh()->part_cost_cents)->toBe($originalCost);
});

it('an unordered job still allows the part cost to be left blank on the Money section', function () {
    $job = partsTestJob($this, '2026-07-20 09:00');
    expect($job->isPartsOrdered())->toBeFalse();

    $component = Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->mountAction(TestAction::make('editMoney')->schemaComponent('money'));

    $rowKey = array_key_first($component->get('mountedActions.0.data.parts_input'));

    $component
        ->setActionData(['parts_input' => [$rowKey => ['label' => 'FW05046GTYN windshield', 'cost_cents' => null]]])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($job->refresh()->part_cost_cents)->toBeNull();
});
