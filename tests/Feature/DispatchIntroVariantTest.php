<?php

use App\Enums\TemplateKey;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Holiday;
use App\Services\JobLifecycle;
use App\Support\Timezone;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    $this->actor = Employee::create([
        'name' => 'Nico', 'email' => 'nico@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ])->assignRole('super_admin');
    $this->lifecycle = app(JobLifecycle::class);
});

afterEach(fn () => Carbon::setTestNow());

dataset('intro times', [
    // [local datetime (PT), expected template key]
    'weekday business hours' => ['2026-06-24 14:00', 'welcome_dispatch_intro'],        // Wed 2pm
    'weekday just before 7pm' => ['2026-06-24 18:59', 'welcome_dispatch_intro'],       // Wed 6:59pm
    'weekday after 7pm' => ['2026-06-24 19:30', 'welcome_dispatch_intro_afterhours'],  // Wed 7:30pm
    'saturday before 4pm' => ['2026-06-27 11:00', 'welcome_dispatch_intro'],           // Sat 11am
    'saturday after 4pm' => ['2026-06-27 16:30', 'welcome_dispatch_intro_sunday'],     // Sat 4:30pm → weekend variant
    'sunday morning' => ['2026-06-28 09:00', 'welcome_dispatch_intro_sunday'],         // Sun 9am
    'sunday late' => ['2026-06-28 23:00', 'welcome_dispatch_intro_sunday'],            // Sun 11pm
]);

it('selects the right dispatch-intro variant for the local time', function (string $local, string $expected) {
    Carbon::setTestNow(Carbon::parse($local, 'America/Los_Angeles'));

    expect(TemplateKey::welcomeDispatchIntroFor(Timezone::now())->value)->toBe($expected);
})->with('intro times');

it('sends the holiday variant any time of day on a holiday', function () {
    Holiday::create(['date' => '2026-07-03']); // a Friday

    // Morning of the holiday.
    Carbon::setTestNow(Carbon::parse('2026-07-03 09:00', 'America/Los_Angeles'));
    expect(TemplateKey::welcomeDispatchIntroFor(Timezone::now())->value)
        ->toBe('welcome_dispatch_intro_holiday');

    // Late evening of the holiday — still the holiday variant (beats after-hours).
    Carbon::setTestNow(Carbon::parse('2026-07-03 21:00', 'America/Los_Angeles'));
    expect(TemplateKey::welcomeDispatchIntroFor(Timezone::now())->value)
        ->toBe('welcome_dispatch_intro_holiday');
});

it('sends the pre-holiday after-hours variant when the next day is a holiday', function () {
    Holiday::create(['date' => '2026-07-03']); // Friday holiday

    // Thursday 8pm — after hours, and tomorrow is a holiday.
    Carbon::setTestNow(Carbon::parse('2026-07-02 20:00', 'America/Los_Angeles'));
    expect(TemplateKey::welcomeDispatchIntroFor(Timezone::now())->value)
        ->toBe('welcome_dispatch_intro_pre_holidayafterhours');

    // Thursday 2pm — business hours, so still the plain intro even pre-holiday.
    Carbon::setTestNow(Carbon::parse('2026-07-02 14:00', 'America/Los_Angeles'));
    expect(TemplateKey::welcomeDispatchIntroFor(Timezone::now())->value)
        ->toBe('welcome_dispatch_intro');
});

it('sendWelcome queues the after-hours variant when sent after 7pm on a weekday', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-24 20:00', 'America/Los_Angeles')); // Wed 8pm

    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Al', 'quote_amount_cents' => 50000,
    ], $this->actor);

    $this->lifecycle->sendWelcome($job, salesAcknowledged: true, actor: $this->actor);

    $row = $job->scheduledMessages()->first();
    expect($row->template_key->value)->toBe('welcome_dispatch_intro_afterhours')
        ->and($row->provider)->toBe('openphone');
});

it('sendWelcome still uses the plain intro during business hours', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-24 10:00', 'America/Los_Angeles')); // Wed 10am

    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551299', 'first_name' => 'Bo', 'quote_amount_cents' => 50000,
    ], $this->actor);

    $this->lifecycle->sendWelcome($job, salesAcknowledged: true, actor: $this->actor);

    expect($job->scheduledMessages()->first()->template_key->value)->toBe('welcome_dispatch_intro');
});
