<?php

use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\Setting;
use App\Services\JobLifecycle;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');
    $this->lifecycle = app(JobLifecycle::class);
});

afterEach(fn () => Carbon::setTestNow());

function newJob($test): Job
{
    return $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Al', 'quote_amount_cents' => 50000,
    ], $test->actor);
}

it('computes night-before (19:30 PT) and morning-of (08:00 PT) in UTC', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-20 12:00', 'America/Los_Angeles'));
    $job = newJob($this);

    // Appointment: June 25, 2026, 2:00 PM Pacific (PDT, UTC-7).
    $appt = Carbon::parse('2026-06-25 14:00', 'America/Los_Angeles')->utc();
    $this->lifecycle->schedule($job, $appt, $this->actor);

    $reminders = $job->refresh()->scheduledMessages()->where('message_type', 'reminder')->get();
    expect($reminders)->toHaveCount(2);

    $nightBefore = $reminders->firstWhere('template_key', 'reminder_night_before');
    $morningOf = $reminders->firstWhere('template_key', 'reminder_morning_of');

    expect($nightBefore->scheduled_for->equalTo(Carbon::parse('2026-06-24 19:30', 'America/Los_Angeles')->utc()))->toBeTrue()
        ->and($morningOf->scheduled_for->equalTo(Carbon::parse('2026-06-25 08:00', 'America/Los_Angeles')->utc()))->toBeTrue();

    // Stored values are UTC.
    expect($nightBefore->scheduled_for->format('Y-m-d H:i'))->toBe('2026-06-25 02:30')   // 19:30 PDT -> 02:30 UTC
        ->and($morningOf->scheduled_for->format('Y-m-d H:i'))->toBe('2026-06-25 15:00'); // 08:00 PDT -> 15:00 UTC
});

it('creates NO reminders for a same-day appointment', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-25 06:00', 'America/Los_Angeles'));
    $job = newJob($this);

    $appt = Carbon::parse('2026-06-25 14:00', 'America/Los_Angeles')->utc(); // today
    $this->lifecycle->schedule($job, $appt, $this->actor);

    expect($job->refresh()->scheduledMessages()->where('message_type', 'reminder')->where('status', 'queued')->count())->toBe(0);
});

it('next-day appointment skips the night-before and sends only the morning-of', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-24 12:00', 'America/Los_Angeles'));
    $job = newJob($this);

    $appt = Carbon::parse('2026-06-25 14:00', 'America/Los_Angeles')->utc(); // tomorrow
    $this->lifecycle->schedule($job, $appt, $this->actor);

    $reminders = $job->refresh()->scheduledMessages()->where('message_type', 'reminder')->where('status', 'queued')->get();
    expect($reminders)->toHaveCount(1)
        ->and($reminders->first()->template_key->value)->toBe('reminder_morning_of');
});

it('appointments 2+ days out get both the night-before and morning-of', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-23 12:00', 'America/Los_Angeles'));
    $job = newJob($this);

    $appt = Carbon::parse('2026-06-25 14:00', 'America/Los_Angeles')->utc(); // two days out
    $this->lifecycle->schedule($job, $appt, $this->actor);

    $reminders = $job->refresh()->scheduledMessages()->where('message_type', 'reminder')->where('status', 'queued')->get();
    expect($reminders)->toHaveCount(2)
        ->and($reminders->firstWhere('template_key', 'reminder_night_before')->scheduled_for
            ->equalTo(Carbon::parse('2026-06-24 19:30', 'America/Los_Angeles')->utc()))->toBeTrue();
});

it('reschedule cancels the old reminders and creates new ones at the new time', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-20 12:00', 'America/Los_Angeles'));
    $job = newJob($this);

    $this->lifecycle->schedule($job, Carbon::parse('2026-06-25 14:00', 'America/Los_Angeles')->utc(), $this->actor);
    $old = $job->refresh()->scheduledMessages()->where('message_type', 'reminder')->pluck('id');

    $this->lifecycle->schedule($job, Carbon::parse('2026-06-28 10:00', 'America/Los_Angeles')->utc(), $this->actor);
    $job->refresh();

    expect($job->scheduledMessages()->whereIn('id', $old)->where('status', 'canceled')->count())->toBe(2);
    $fresh = $job->scheduledMessages()->where('message_type', 'reminder')->where('status', 'queued')->get();
    expect($fresh)->toHaveCount(2)
        ->and($fresh->firstWhere('template_key', 'reminder_morning_of')->scheduled_for
            ->equalTo(Carbon::parse('2026-06-28 08:00', 'America/Los_Angeles')->utc()))->toBeTrue();
});

it('honors admin-configured reminder times from settings', function () {
    Setting::put('reminder_night_before_at', '20:30');
    Setting::put('reminder_morning_of_at', '07:15');

    Carbon::setTestNow(Carbon::parse('2026-06-20 12:00', 'America/Los_Angeles'));
    $job = newJob($this);
    $this->lifecycle->schedule($job, Carbon::parse('2026-06-25 14:00', 'America/Los_Angeles')->utc(), $this->actor);

    $reminders = $job->refresh()->scheduledMessages()->where('message_type', 'reminder')->get();
    expect($reminders->firstWhere('template_key', 'reminder_night_before')->scheduled_for
        ->equalTo(Carbon::parse('2026-06-24 20:30', 'America/Los_Angeles')->utc()))->toBeTrue()
        ->and($reminders->firstWhere('template_key', 'reminder_morning_of')->scheduled_for
            ->equalTo(Carbon::parse('2026-06-25 07:15', 'America/Los_Angeles')->utc()))->toBeTrue();
});
