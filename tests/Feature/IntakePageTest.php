<?php

use App\Filament\Pages\Intake;
use App\Filament\Pages\SendWelcome;
use App\Models\Employee;
use App\Models\Job;
use App\Models\PodiumMessage;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->user = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->user->assignRole('dispatch');
    $this->actingAs($this->user);

    // The booking rep is named in the welcome text, so intake requires one.
    $this->rep = Employee::create([
        'name' => 'Rodolfo', 'email' => 'rodolfo@test.local', 'active' => true, 'job_functions' => ['sales'],
    ]);
});

$fixtureA = <<<'TXT'
City: Los Angeles
Name: Alan Fairview
Phone: 818-555-8014
Vehicle: 2022 Tesla Model Y - 7SAYGDEF3NF000287 W/S
Part#: DW2853GTY $350.00
Quote: 950
Req. Day/Time: Thursday
Entity: LA
TXT;

it('parses fixture A in the intake page and resolves the entity', function () use ($fixtureA) {
    Livewire::test(Intake::class)
        ->set('data.raw_text', $fixtureA)
        ->call('parse')
        ->assertSet('data.first_name', 'Alan')
        ->assertSet('data.phone_e164', '+18185558014')
        ->assertSet('data.vehicle_make', 'Tesla')
        ->assertSet('data.quote', 950.0);

    // Clean parse -> no warnings surfaced.
    expect((array) Livewire::test(Intake::class)->set('data.raw_text', $fixtureA)->call('parse')->get('warnings'))->toBeEmpty();
});

it('merges a city-only lead into the single service-address field', function () use ($fixtureA) {
    // Fixture A carries only "City: Los Angeles" and no street Address line.
    Livewire::test(Intake::class)
        ->set('data.raw_text', $fixtureA)
        ->call('parse')
        ->assertSet('data.service_address', 'Los Angeles');
});

it('merges a street address + city without duplicating the city', function () {
    $lead = <<<'TXT'
City: Sylmar
Name: Ron Seaton
Phone: 323-555-3658
Address: 14163 Saddletree Ct, Sylmar, CA 91342, USA
Vehicle: 2020 Honda Pilot - 5FNYF6H56LB000535 W/S
Quote: 682
Entity: LA
TXT;

    Livewire::test(Intake::class)
        ->set('data.raw_text', $lead)
        ->call('parse')
        // City "Sylmar" is already in the address, so it's not appended again.
        ->assertSet('data.service_address', '14163 Saddletree Ct, Sylmar, CA 91342, USA');
});

it('requires phone and first name before creating a job', function () {
    Livewire::test(Intake::class)
        ->set('data.entity_id', 1)
        ->set('data.phone_e164', null)
        ->set('data.first_name', null)
        ->call('create')
        ->assertHasErrors(['data.phone_e164', 'data.first_name']);

    expect(Job::count())->toBe(0);
});

it('creates a job from fixture A through the intake page', function () use ($fixtureA) {
    Livewire::test(Intake::class)
        ->set('data.raw_text', $fixtureA)
        ->call('parse')
        ->set('data.booking_salesperson_id', $this->rep->id)
        ->call('create')
        ->assertHasNoErrors();

    $job = Job::first();
    expect($job)->not->toBeNull()
        ->and($job->booking_salesperson_id)->toBe($this->rep->id)
        ->and($job->vehicle_make)->toBe('Tesla')
        ->and($job->quote_amount_cents)->toBe(95000)
        ->and($job->part_cost_cents)->toBe(35000)
        // No welcome is auto-queued: the dispatcher chooses it on SendWelcome.
        ->and($job->scheduledMessages()->count())->toBe(0);
});

it('intake redirects to the SendWelcome screen for the new job', function () use ($fixtureA) {
    Livewire::test(Intake::class)
        ->set('data.raw_text', $fixtureA)
        ->call('parse')
        ->set('data.booking_salesperson_id', $this->rep->id)
        ->call('create')
        ->assertRedirect(SendWelcome::getUrl(['job' => Job::first()->id]));
});

it('refuses to create a job without a booking salesperson', function () use ($fixtureA) {
    // Their name opens the welcome text ("{salesperson} just passed your
    // appointment over to me"), so a blank leaves the customer's first message
    // from us starting mid-sentence.
    Livewire::test(Intake::class)
        ->set('data.raw_text', $fixtureA)
        ->call('parse')
        ->set('data.booking_salesperson_id', null)
        ->call('create')
        ->assertHasErrors(['data.booking_salesperson_id']);

    expect(Job::count())->toBe(0);
});

$fixtureE = <<<'TXT'
City: Northridge
Name: Susan West
Phone: 818-555-4306
17043 Gresham St Northridge, CA 91325
Vehicle: 2009 Acura TSX - JH4CU26689C000006 W/S
Part#: FW02994 GBN FYG $101.75 WFT F2994 PRP $11.72
Quote: $370.00
Text/Call Pref: Call
Entity: SFV
TXT;

it('auto-detects the booking salesperson from the Podium sales thread', function () use ($fixtureE) {
    $marcel = Employee::create([
        'name' => 'Marcel Rivera', 'email' => 'marcel@test.local',
        'active' => true, 'job_functions' => ['sales'],
    ]);

    foreach ([
        'Hello Susan! Marcel here from San Fernando Auto Glass',
        'Hi Susan. This is Marcel again — you are all set.',
    ] as $i => $body) {
        PodiumMessage::create([
            'direction' => 'outbound',
            'contact_phone_e164' => '+18185554306',
            'body' => $body,
            'occurred_at' => now()->subMinutes(10 - $i),
            'provider_message_id' => 'pm-'.$i,
        ]);
    }

    Livewire::test(Intake::class)
        ->set('data.raw_text', $fixtureE)
        ->call('parse')
        ->assertSet('data.phone_e164', '+18185554306')
        ->assertSet('data.booking_salesperson_id', $marcel->id);
});

it('does not auto-detect a salesperson when the rep is not named in the thread', function () use ($fixtureE) {
    Employee::create([
        'name' => 'Nico Park', 'email' => 'nico@test.local',
        'active' => true, 'job_functions' => ['sales'],
    ]);

    PodiumMessage::create([
        'direction' => 'outbound',
        'contact_phone_e164' => '+18185554306',
        'body' => 'Hello Susan! Your glass is in stock.',
        'occurred_at' => now()->subMinutes(5),
        'provider_message_id' => 'pm-x',
    ]);

    Livewire::test(Intake::class)
        ->set('data.raw_text', $fixtureE)
        ->call('parse')
        ->assertSet('data.booking_salesperson_id', null);
});

it('surfaces a warning for fixture C (missing entity) and requires the operator to pick one', function () {
    $fixtureC = "Name: Bob O'Brien\nCar: Chevy Silverado 1500 2018 1GCUKREC0JF000222 back glass\nPhone: 818.555.7777\nQuote: 600\nPart Number: DB1234 \$300\nContact Pref: call";

    $component = Livewire::test(Intake::class)
        ->set('data.raw_text', $fixtureC)
        ->call('parse')
        ->assertSet('data.first_name', 'Bob')
        ->assertSet('data.entity_id', null);

    expect(collect($component->get('warnings'))->contains(fn ($w) => str_contains($w, 'No entity')))->toBeTrue();
});
