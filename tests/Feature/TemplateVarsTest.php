<?php

use App\Enums\TemplateKey;
use App\Models\Employee;
use App\Models\Entity;
use App\Services\JobLifecycle;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateRenderException;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    $this->actor = Employee::create([
        'name' => 'Nico', 'email' => 'nico@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ])->assignRole('super_admin');
    $this->lifecycle = app(JobLifecycle::class);
});

afterEach(fn () => Carbon::setTestNow());

function varsJob($test)
{
    return $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Susan', 'quote_amount_cents' => 50000,
        'requested_daytime_raw' => 'Thursday afternoon',
    ], $test->actor);
}

it('exposes preferred + scheduled date/time and drops full_name', function () {
    $job = varsJob($this);
    $vars = app(TemplateRenderer::class)->varsForJob($job);

    expect($vars)->toHaveKeys(['preferred_date_time', 'scheduled_date_time'])
        ->and($vars)->not->toHaveKey('full_name')
        ->and($vars['preferred_date_time'])->toBe('Thursday afternoon')
        ->and($vars['scheduled_date_time'])->toBe('');           // no appointment yet
});

// {warehouse} resolves from the job for the tech's job card. The AI
// warehouse-set text still passes it as an extra var, which overrides this.
it('resolves {warehouse} from the job, empty until a parts source is picked', function () {
    $job = varsJob($this);
    $renderer = app(TemplateRenderer::class);

    expect($renderer->varsForJob($job)['warehouse'])->toBe('');

    $job->update(['warehouse_other' => 'Sunland Auto Glass']);

    expect($renderer->varsForJob($job->refresh())['warehouse'])->toBe('Sunland Auto Glass');
});

it('the options list (SUPPORTED_VARS) drops full_name and adds the date/time + window vars', function () {
    expect(TemplateRenderer::SUPPORTED_VARS)->toContain('preferred_date_time', 'scheduled_date_time', 'appointment_window')
        ->and(TemplateRenderer::SUPPORTED_VARS)->toContain('warehouse', 'customer_phone', 'service_address')
        ->and(TemplateRenderer::SUPPORTED_VARS)->not->toContain('full_name')
        ->and(TemplateRenderer::SUPPORTED_VARS)->not->toContain('date_time');
});

it('renders an empty {appointment_window} until an appointment is scheduled', function () {
    $job = varsJob($this);
    $vars = app(TemplateRenderer::class)->varsForJob($job);

    expect($vars)->toHaveKey('appointment_window')
        ->and($vars['appointment_window'])->toBe('');
});

it('scheduled_date_time defaults to a 2-hour arrival window, and {date_time} still resolves (alias)', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-22 12:00', 'America/Los_Angeles'));
    $job = varsJob($this);
    // 2pm appointment → "Thursday, June 25th between 2pm-4pm".
    $this->lifecycle->schedule($job, Carbon::parse('2026-06-25 14:00', 'America/Los_Angeles')->utc(), $this->actor);

    $renderer = app(TemplateRenderer::class);
    $vars = $renderer->varsForJob($job->refresh());

    expect($vars['scheduled_date_time'])->toContain('June 25th')
        ->and($vars['scheduled_date_time'])->toContain('between 2pm')
        ->and($vars['scheduled_date_time'])->toContain('4pm')
        ->and($vars['appointment_window'])->toContain('2pm')
        ->and($vars['appointment_window'])->toContain('4pm')
        ->and($renderer->render('See you {date_time}', $vars))->toBe('See you '.$vars['scheduled_date_time']);
});

it('scheduled_date_time gives an exact time (no window) when the job is flagged exact-time', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-22 12:00', 'America/Los_Angeles'));
    $job = varsJob($this);
    $this->lifecycle->schedule($job, Carbon::parse('2026-06-25 14:00', 'America/Los_Angeles')->utc(), $this->actor, exactTime: true);

    $vars = app(TemplateRenderer::class)->varsForJob($job->refresh());

    expect($vars['scheduled_date_time'])->toContain('June 25th')
        ->and($vars['scheduled_date_time'])->toContain('at 2pm')
        ->and($vars['scheduled_date_time'])->not->toContain('between')
        ->and($vars['appointment_window'])->toBe('2pm');
});

it('scheduled_date_time widens to the per-job window when a 3- or 4-hour window is chosen', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-22 12:00', 'America/Los_Angeles'));
    $job = varsJob($this);
    // 2pm appointment + a 3-hour window → "between 2pm-5pm".
    $this->lifecycle->schedule($job, Carbon::parse('2026-06-25 14:00', 'America/Los_Angeles')->utc(), $this->actor, exactTime: false, windowHours: 3);

    $vars = app(TemplateRenderer::class)->varsForJob($job->refresh());

    expect($vars['scheduled_date_time'])->toContain('June 25th')
        ->and($vars['scheduled_date_time'])->toContain('between 2pm')
        ->and($vars['scheduled_date_time'])->toContain('5pm')
        ->and($vars['appointment_window'])->toBe('2pm-5pm');

    // 4-hour window on reschedule → "between 2pm-6pm".
    $this->lifecycle->schedule($job->refresh(), Carbon::parse('2026-06-25 14:00', 'America/Los_Angeles')->utc(), $this->actor, exactTime: false, windowHours: 4);
    $vars = app(TemplateRenderer::class)->varsForJob($job->refresh());
    expect($vars['appointment_window'])->toBe('2pm-6pm');
});

it('rescheduling back to the default clears a custom window (and exact-time never keeps one)', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-22 12:00', 'America/Los_Angeles'));
    $job = varsJob($this);
    $when = Carbon::parse('2026-06-25 14:00', 'America/Los_Angeles')->utc();
    $this->lifecycle->schedule($job, $when, $this->actor, exactTime: false, windowHours: 4);
    expect($job->refresh()->scheduled_window_hours)->toBe(4);

    // Back to the default 2-hour window.
    $this->lifecycle->schedule($job, $when, $this->actor, exactTime: false, windowHours: null);
    $vars = app(TemplateRenderer::class)->varsForJob($job->refresh());
    expect($job->scheduled_window_hours)->toBeNull()
        ->and($vars['appointment_window'])->toBe('2pm-4pm');

    // Choosing exact time drops any stale custom window too.
    $this->lifecycle->schedule($job, $when, $this->actor, exactTime: false, windowHours: 3);
    $this->lifecycle->schedule($job->refresh(), $when, $this->actor, exactTime: true);
    expect($job->refresh()->scheduled_window_hours)->toBeNull()
        ->and($job->scheduled_exact_time)->toBeTrue();
});

it('time-templated messages require the scheduled (not preferred) time', function () {
    $job = varsJob($this); // has preferred time, but no appointment scheduled
    $renderer = app(TemplateRenderer::class);

    // Welcome (preferred) renders fine without an appointment...
    expect($renderer->renderForJob(TemplateKey::WelcomeDispatchIntro, $job))->toContain('Thursday afternoon');

    // ...but a confirmation needs the scheduled time and refuses to render.
    expect(fn () => $renderer->renderForJob(TemplateKey::TimeConfirmation, $job))
        ->toThrow(TemplateRenderException::class);
});
