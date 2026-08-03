<?php

use App\Enums\JobFunction;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\TemplateKey;
use App\Filament\Pages\SendWelcome;
use App\Filament\Resources\Jobs\Pages\ViewJob;
use App\Jobs\SendSmsJob;
use App\Models\Employee;
use App\Models\Entity;
use App\Services\JobLifecycle;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ])->assignRole('super_admin');

    $this->actingAs($this->actor);
    $this->lifecycle = app(JobLifecycle::class);

    $this->entityId = Entity::where('code', 'LA')->value('id');
});

afterEach(fn () => Carbon::setTestNow());

function availabilityJob($test)
{
    return $test->lifecycle->createFromIntake([
        'entity_id' => $test->entityId,
        'phone_e164' => '+18185550000',
        'first_name' => 'Cust',
        'city' => 'Sylmar',
        'part_number' => 'FW04123',
        'glass_position' => 'Windshield',
        'vehicle_year' => '2020', 'vehicle_make' => 'Toyota', 'vehicle_model' => 'Camry',
        'requested_daytime_raw' => 'Tomorrow morning',
    ], $test->actor);
}

function tech($name, $entityId, $phone = '+13105551111')
{
    return Employee::create([
        'name' => $name, 'email' => str($name)->slug().'@test.local',
        'phone' => $phone, 'entity_id' => $entityId, 'active' => true,
        'can_login' => false, 'job_functions' => [JobFunction::Technician->value],
    ]);
}

it('texts each selected tech a TechOutreach row from the Quo line with the Spanish body', function () {
    Queue::fake();
    $job = availabilityJob($this);
    $t1 = tech('Marco', $this->entityId, '+13105550001');
    $t2 = tech('Luis', $this->entityId, '+13105550002');

    $count = $this->lifecycle->sendTechAvailabilityRequests($job, [$t1->id, $t2->id], $this->actor);

    expect($count)->toBe(2);

    $rows = $job->scheduledMessages()->where('message_type', MessageType::TechOutreach->value)->get();
    expect($rows)->toHaveCount(2);

    $row = $rows->firstWhere('employee_id', $t1->id);
    expect($row->to_phone_e164)->toBe('+13105550001')
        ->and($row->provider)->toBe('openphone')
        ->and($row->status)->toBe(MessageStatus::Queued)
        ->and($row->template_key)->toBe(TemplateKey::TechJobAvailability)
        ->and($row->rendered_body)->toContain('Ciudad: Sylmar')
        ->and($row->rendered_body)->toContain('Parte: FW04123')
        ->and($row->rendered_body)->toContain('Trabajo: Windshield')
        ->and($row->rendered_body)->toContain('2020 Toyota Camry')
        ->and($row->rendered_body)->toContain('Tomorrow morning');

    Queue::assertPushed(SendSmsJob::class, 2);
});

it('is idempotent per job + tech — re-running does not double-text', function () {
    Queue::fake();
    $job = availabilityJob($this);
    $t1 = tech('Marco', $this->entityId);

    $this->lifecycle->sendTechAvailabilityRequests($job, [$t1->id], $this->actor);
    $this->lifecycle->sendTechAvailabilityRequests($job, [$t1->id], $this->actor);

    expect($job->scheduledMessages()->where('employee_id', $t1->id)->count())->toBe(1);
});

it('skips a tech with no usable phone and counts only those texted', function () {
    Queue::fake();
    $job = availabilityJob($this);
    $withPhone = tech('Marco', $this->entityId, '+13105550009');
    $noPhone = tech('Phoneless', $this->entityId, null);

    $count = $this->lifecycle->sendTechAvailabilityRequests($job, [$withPhone->id, $noPhone->id], $this->actor);

    expect($count)->toBe(1)
        ->and($job->scheduledMessages()->where('message_type', MessageType::TechOutreach->value)->count())->toBe(1);
});

it('the job view "Text techs for availability" action texts the selected techs', function () {
    Queue::fake();
    $job = availabilityJob($this);
    $t1 = tech('Marco', $this->entityId, '+13105550001');
    tech('Luis', $this->entityId, '+13105550002');

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('textTechsAvailability', ['tech_ids' => [$t1->id]]);

    $rows = $job->scheduledMessages()->where('message_type', MessageType::TechOutreach->value)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->employee_id)->toBe($t1->id);
});

it('the job view action also pools LA and SFV techs', function () {
    Queue::fake();
    $job = availabilityJob($this); // LA entity
    $sfvTech = tech('Luis', Entity::where('code', 'SFV')->value('id'), '+13105550002');

    // Succeeding proves the SFV tech was in the action's option list — Filament
    // rejects a submitted value that isn't among the field's options.
    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('textTechsAvailability', ['tech_ids' => [$sfvTech->id]]);

    $rows = $job->scheduledMessages()->where('message_type', MessageType::TechOutreach->value)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->employee_id)->toBe($sfvTech->id);
});

it('the send-welcome screen offers the action right after the welcome — even during business hours', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-24 12:00', 'America/Los_Angeles')); // Wed, business hours
    $job = availabilityJob($this);
    $t1 = tech('Marco', $this->entityId, '+13105550001');

    // Queuing the welcome bounces back to the same page (fresh request), where
    // the availability action is now offered.
    Livewire::test(SendWelcome::class, ['job' => $job->id])
        ->callAction('salesAcknowledged')
        ->assertRedirect(SendWelcome::getUrl(['job' => $job->id]));

    Livewire::test(SendWelcome::class, ['job' => $job->id])
        ->assertActionVisible('requestTechAvailability')
        ->callAction('requestTechAvailability', ['tech_ids' => [$t1->id]]);

    expect($job->scheduledMessages()->where('message_type', MessageType::TechOutreach->value)->count())->toBe(1);
});

it('still texts techs even when the customer has opted out (recipient is the tech)', function () {
    Queue::fake();
    $job = availabilityJob($this);
    $job->customer->update(['do_not_text' => true]);
    $t1 = tech('Marco', $this->entityId);

    $count = $this->lifecycle->sendTechAvailabilityRequests($job, [$t1->id], $this->actor);

    expect($count)->toBe(1);
    Queue::assertPushed(SendSmsJob::class, 1);
});

it('pools LA and SFV techs — a job at either entity can text techs registered at the other', function () {
    $job = availabilityJob($this); // LA entity
    $sfvEntityId = Entity::where('code', 'SFV')->value('id');

    $laTech = tech('Marco', $this->entityId, '+13105550001');
    $sfvTech = tech('Luis', $sfvEntityId, '+13105550002');
    $delMarTech = tech('Ana', Entity::where('code', 'DelMar')->value('id'), '+13105550003');

    $options = Livewire::test(SendWelcome::class, ['job' => $job->id])
        ->instance()
        ->locationTechOptions();

    expect($options)->toHaveKey($laTech->id)
        ->toHaveKey($sfvTech->id)
        ->not->toHaveKey($delMarTech->id);
});
