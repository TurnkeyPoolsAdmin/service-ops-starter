<?php

use App\Enums\MessageStatus;
use App\Filament\Pages\SendWelcome;
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
    // Welcomes now dispatch their send jobs immediately; fake the queue so rows
    // stay queued for assertion instead of being sent by the sync worker.
    Queue::fake();

    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ])->assignRole('super_admin');

    $this->actingAs($this->actor);
});

afterEach(fn () => Carbon::setTestNow());

function welcomeJob($test)
{
    return app(JobLifecycle::class)->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Al', 'quote_amount_cents' => 50000,
    ], $test->actor);
}

it('renders the send-welcome screen for a job with both message previews', function () {
    $job = welcomeJob($this);

    $this->get(SendWelcome::getUrl(['job' => $job->id]))
        ->assertSuccessful()
        ->assertSee('Dispatch intro')
        ->assertSee('handoff');
});

it('the acknowledged action queues a single dispatch intro via Quo', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-24 12:00', 'America/Los_Angeles')); // Wed, business hours
    $job = welcomeJob($this);

    Livewire::test(SendWelcome::class, ['job' => $job->id])
        ->callAction('salesAcknowledged');

    $rows = $job->scheduledMessages()->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->template_key->value)->toBe('welcome_dispatch_intro')
        ->and($rows->first()->provider)->toBe('openphone')
        ->and($rows->first()->status)->toBe(MessageStatus::Queued);
});

it('the not-acknowledged action queues a Podium handoff plus a delayed Quo intro', function () {
    $job = welcomeJob($this);

    Livewire::test(SendWelcome::class, ['job' => $job->id])
        ->callAction('salesNotAcknowledged');

    expect($job->scheduledMessages()->count())->toBe(2)
        ->and($job->scheduledMessages()->where('provider', 'podium')->count())->toBe(1)
        ->and($job->scheduledMessages()->where('provider', 'openphone')->count())->toBe(1);
});

it('skipping queues nothing', function () {
    $job = welcomeJob($this);

    Livewire::test(SendWelcome::class, ['job' => $job->id])
        ->callAction('skip');

    expect($job->scheduledMessages()->count())->toBe(0);
});

it('offers the scheduling ask on the screen once the welcome has gone out', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-20 12:00', 'America/Los_Angeles')); // Mon
    $job = app(JobLifecycle::class)->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Al',
        'requested_day' => '2026-07-24', // Friday - far enough out to offer earlier days
    ], $this->actor);

    Livewire::test(SendWelcome::class, ['job' => $job->id])
        ->callAction('salesAcknowledged');

    Livewire::test(SendWelcome::class, ['job' => $job->id])
        ->assertActionVisible('sendSchedulingAsk')
        ->callAction('sendSchedulingAsk');

    $ask = $job->scheduledMessages()->where('template_key', 'scheduling_ask_future')->first();

    expect($ask)->not->toBeNull()
        ->and($ask->rendered_body)->toContain('Friday, July 24')
        ->and($ask->rendered_body)->toContain('tomorrow (Tuesday)');
});

it('hides the scheduling ask for a job requested today', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-20 12:00', 'America/Los_Angeles'));
    $job = app(JobLifecycle::class)->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Al',
        'requested_day' => '2026-07-20',
    ], $this->actor);

    Livewire::test(SendWelcome::class, ['job' => $job->id])->callAction('salesAcknowledged');

    // Not merely hidden — the screen never registers it, so there is no
    // half-live button for a job we should be calling instead.
    Livewire::test(SendWelcome::class, ['job' => $job->id])
        ->assertActionDoesNotExist('sendSchedulingAsk');
});

// ------------------------------------------------------- job details panel

it('shows the whole lead in a details panel at the bottom of the page', function () {
    $job = app(JobLifecycle::class)->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Al', 'last_name' => 'Ramos',
        'service_address' => '123 Main St', 'city' => 'Los Angeles',
        'vehicle_year' => '2021', 'vehicle_make' => 'Honda', 'vehicle_model' => 'Civic',
        'glass_position' => 'Windshield',
        'part_number' => 'FW05339', 'part_available' => true,
        'quote_amount_cents' => 45000,
        'is_insurance' => true, 'insurance_carrier' => 'Geico',
        'requested_daytime_raw' => 'Tomorrow morning',
        'booking_salesperson_name' => 'Alex B',
        'notes' => 'Park in the driveway.',
    ], $this->actor);

    // Shows for EVERY job, manual or auto-intaken.
    $this->get(SendWelcome::getUrl(['job' => $job->id]))
        ->assertSuccessful()
        ->assertSee('Job details')
        ->assertSee($job->job_number)
        ->assertSee('Auto Glass of LA')
        ->assertSee('Al Ramos')
        ->assertSee('(818) 555-1234')
        ->assertSee('123 Main St, Los Angeles')
        ->assertSee('2021 Honda Civic')
        ->assertSee('Windshield')
        ->assertSee('FW05339')
        ->assertSee('$450.00')
        ->assertSee('Yes - Geico')
        ->assertSee('Tomorrow morning')
        ->assertSee('Alex B')
        ->assertSee('Park in the driveway.');
});

it('reads the booking rep off the employee relation when there is no snapshot', function () {
    $rep = Employee::create([
        'name' => 'Marcel', 'email' => 'marcel@test.local', 'active' => true,
        'job_functions' => ['sales'],
    ]);

    $job = app(JobLifecycle::class)->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Al',
        'booking_salesperson_id' => $rep->id,
    ], $this->actor);

    expect($job->bookingSalespersonName())->toBe('Marcel');

    $this->get(SendWelcome::getUrl(['job' => $job->id]))->assertSee('Marcel');
});
