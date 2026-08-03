<?php

use App\Enums\Language;
use App\Enums\TemplateKey;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\ScheduledMessage;
use App\Models\Warehouse;
use App\Services\JobLifecycle;
use App\Support\PhoneNumber;
use App\Support\Timezone;
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
        'name' => 'Op', 'email' => 'tjc-op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->tech = Employee::create([
        'name' => 'Jose Ramirez', 'email' => 'tjc-tech@test.local', 'can_login' => false,
        'active' => true, 'password' => bcrypt('x'), 'job_functions' => ['technician'],
        'phone' => '+13105550101', 'preferred_language' => Language::English->value,
    ]);

    $this->lifecycle = app(JobLifecycle::class);
});

function tjcIntakeData(array $extra = []): array
{
    return $extra + [
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Alan',
        'last_name' => 'Fairview',
        'service_address' => '742 Evergreen Terrace',
        'city' => 'Van Nuys',
        'vehicle_year' => '2022', 'vehicle_make' => 'Tesla', 'vehicle_model' => 'Model Y',
        'glass_position' => 'Windshield',
        'part_number' => 'FW04321',
        'quote_amount_cents' => 95000,
        'requested_daytime_raw' => 'Thursday',
        'intake_raw_text' => 'raw', 'parse_status' => 'clean',
    ];
}

/** The one tech job-confirmation row for a job, or null. */
function tjcMessages(int $jobId)
{
    return ScheduledMessage::where('job_id', $jobId)
        ->where('template_key', TemplateKey::TechJobConfirmation->value)
        ->orderBy('id')
        ->get();
}

it('texts the assigned tech every detail of the job the moment it is scheduled', function () {
    $job = $this->lifecycle->createFromIntake(tjcIntakeData(), $this->actor);
    $warehouse = Warehouse::where('active', true)->firstOrFail();

    $this->lifecycle->schedule(
        $job,
        Timezone::now()->next('Monday')->setTime(8, 0)->utc(),
        $this->actor,
        technicianId: $this->tech->id,
        warehouseId: $warehouse->id,
    );

    $messages = tjcMessages($job->id);
    expect($messages)->toHaveCount(1);

    $body = $messages->first()->rendered_body;

    expect($messages->first()->to_phone_e164)->toBe('+13105550101')
        ->and($messages->first()->employee_id)->toBe($this->tech->id)
        // 1 name, 2 phone, 3 address, 4 time, 5 vehicle, 6 part #, 7 warehouse.
        ->and($body)->toContain('Alan')
        ->and($body)->toContain('(818) 555-1234')
        ->and($body)->toContain('742 Evergreen Terrace, Van Nuys')
        ->and($body)->toContain('Monday')
        ->and($body)->toContain('between 8am-10am')
        ->and($body)->toContain('2022 Tesla Model Y')
        ->and($body)->toContain('FW04321')
        ->and($body)->toContain($warehouse->name);
});

it('says the warehouse is still outstanding when none has been picked', function () {
    $job = $this->lifecycle->createFromIntake(tjcIntakeData(), $this->actor);

    $this->lifecycle->schedule(
        $job,
        Timezone::now()->next('Monday')->setTime(8, 0)->utc(),
        $this->actor,
        technicianId: $this->tech->id,
    );

    expect(tjcMessages($job->id)->first()->rendered_body)
        ->toContain('Waiting on you to select warehouse');
});

it('sends nothing until the job has BOTH a time and a tech', function () {
    $job = $this->lifecycle->createFromIntake(tjcIntakeData(), $this->actor);

    // Scheduled, but nobody assigned yet.
    $this->lifecycle->schedule(
        $job, Timezone::now()->next('Monday')->setTime(8, 0)->utc(), $this->actor,
    );
    expect(tjcMessages($job->id))->toHaveCount(0);

    // Assigning the tech is what completes the pair, so the card goes out now.
    $this->lifecycle->assignTechnician($job->refresh(), $this->tech->id, $this->actor);
    expect(tjcMessages($job->id))->toHaveCount(1);
});

it('never sends for a tech assigned to a job that has no time yet', function () {
    $job = $this->lifecycle->createFromIntake(tjcIntakeData(), $this->actor);

    $this->lifecycle->assignTechnician($job, $this->tech->id, $this->actor);

    expect(tjcMessages($job->id))->toHaveCount(0);
});

it('sends once per appointment, however many times the same booking is saved', function () {
    $job = $this->lifecycle->createFromIntake(tjcIntakeData(), $this->actor);
    $at = Timezone::now()->next('Monday')->setTime(8, 0)->utc();

    $this->lifecycle->schedule($job, $at, $this->actor, technicianId: $this->tech->id);
    $this->lifecycle->schedule($job->refresh(), $at, $this->actor, technicianId: $this->tech->id);

    expect(tjcMessages($job->id))->toHaveCount(1);
});

it('sends a fresh, corrected card on a reschedule', function () {
    $job = $this->lifecycle->createFromIntake(tjcIntakeData(), $this->actor);
    $monday = Timezone::now()->next('Monday')->setTime(8, 0)->utc();

    $this->lifecycle->schedule($job, $monday, $this->actor, technicianId: $this->tech->id);
    $this->lifecycle->schedule(
        $job->refresh(),
        Timezone::now()->next('Tuesday')->setTime(13, 0)->utc(),
        $this->actor,
        technicianId: $this->tech->id,
    );

    $messages = tjcMessages($job->id);

    expect($messages)->toHaveCount(2)
        ->and($messages->first()->rendered_body)->toContain('Monday')
        ->and($messages->last()->rendered_body)->toContain('Tuesday')
        ->and($messages->last()->rendered_body)->toContain('between 1pm-3pm');
});

it('sends a new tech their own card when the job changes hands', function () {
    $job = $this->lifecycle->createFromIntake(tjcIntakeData(), $this->actor);
    $other = Employee::create([
        'name' => 'Marco Diaz', 'email' => 'tjc-tech2@test.local', 'can_login' => false,
        'active' => true, 'password' => bcrypt('x'), 'job_functions' => ['technician'],
        'phone' => '+13105550202', 'preferred_language' => Language::English->value,
    ]);

    $this->lifecycle->schedule(
        $job, Timezone::now()->next('Monday')->setTime(8, 0)->utc(), $this->actor,
        technicianId: $this->tech->id,
    );
    $this->lifecycle->assignTechnician($job->refresh(), $other->id, $this->actor);

    $messages = tjcMessages($job->id);

    expect($messages)->toHaveCount(2)
        ->and($messages->pluck('employee_id')->all())->toBe([$this->tech->id, $other->id]);
});

it('writes the card in the tech language, not the customer language', function () {
    $job = $this->lifecycle->createFromIntake(tjcIntakeData(), $this->actor);
    $this->tech->update(['preferred_language' => Language::Spanish->value]);

    $this->lifecycle->schedule(
        $job, Timezone::now()->next('Monday')->setTime(8, 0)->utc(), $this->actor,
        technicianId: $this->tech->id,
    );

    $body = tjcMessages($job->id)->first()->rendered_body;

    expect($body)->toContain('este trabajo es tuyo')
        ->and($body)->toContain('lunes')
        ->and($body)->toContain('Esperando a que escojas el almacen');
});

it('formats a US phone for a human and leaves anything else alone', function () {
    expect(PhoneNumber::toDisplay('+18185551234'))->toBe('(818) 555-1234')
        ->and(PhoneNumber::toDisplay('8185551234'))->toBe('(818) 555-1234')
        ->and(PhoneNumber::toDisplay('+442071838750'))->toBe('+442071838750')
        ->and(PhoneNumber::toDisplay(null))->toBeNull();
});
