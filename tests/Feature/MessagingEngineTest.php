<?php

use App\Enums\MessageStatus;
use App\Enums\TemplateKey;
use App\Jobs\SendSmsJob;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Services\JobLifecycle;
use App\Services\Messaging\OutboxService;
use App\Services\Messaging\Providers\MessageProvider;
use App\Services\Messaging\Providers\MessageProviderFactory;
use App\Services\Messaging\Providers\ProviderResult;
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

function makeJobWithWelcome($test): Job
{
    $job = $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Alan',
        'quote_amount_cents' => 95000,
    ], $test->actor);

    // The welcome is no longer auto-queued at intake. Queue one dispatch-intro
    // row directly (without dispatching) so these tests drive sending themselves.
    app(OutboxService::class)->queueForJob($job, TemplateKey::WelcomeDispatchIntro, null, $test->actor->id, 'openphone');

    return $job;
}

/** Route the worker's provider factory to a test double. */
function fakeSmsProviderFactory(MessageProvider $provider): void
{
    $factory = Mockery::mock(MessageProviderFactory::class);
    $factory->shouldReceive('make')->andReturn($provider);
    app()->instance(MessageProviderFactory::class, $factory);
}

it('sends a queued welcome via the provider and marks it sent', function () {
    $job = makeJobWithWelcome($this);
    $msg = $job->scheduledMessages()->first();
    expect($msg->status)->toBe(MessageStatus::Queued);

    $this->artisan('messages:process-due')->assertSuccessful();

    $msg->refresh();
    expect($msg->status)->toBe(MessageStatus::Sent)
        ->and($msg->provider_message_id)->not->toBeNull()
        ->and($msg->sent_at)->not->toBeNull();
});

it('sends exactly once even when the same row is processed twice (idempotency)', function () {
    $job = makeJobWithWelcome($this);
    $msg = $job->scheduledMessages()->first();

    // Provider must be called exactly once across two job runs.
    $mock = Mockery::mock(MessageProvider::class);
    $mock->shouldReceive('send')->once()->andReturn(ProviderResult::ok('pm_123'));
    fakeSmsProviderFactory($mock);

    SendSmsJob::dispatchSync($msg->id);
    SendSmsJob::dispatchSync($msg->id);

    $msg->refresh();
    expect($msg->status)->toBe(MessageStatus::Sent)
        ->and($msg->provider_message_id)->toBe('pm_123');
});

it('skips sending when the customer has opted out', function () {
    $job = makeJobWithWelcome($this);
    $msg = $job->scheduledMessages()->first();

    $job->customer->update(['do_not_text' => true]);

    $mock = Mockery::mock(MessageProvider::class);
    $mock->shouldReceive('send')->never();
    fakeSmsProviderFactory($mock);

    SendSmsJob::dispatchSync($msg->id);

    expect($msg->refresh()->status)->toBe(MessageStatus::Skipped)
        ->and($msg->skip_reason)->toBe('customer opted out');
});

it('requeues on transient failure and fails after max attempts', function () {
    config()->set('glass.send.max_attempts', 3);
    config()->set('glass.send.backoff', [0, 0, 0]);

    $job = makeJobWithWelcome($this);
    $msg = $job->scheduledMessages()->first();

    $mock = Mockery::mock(MessageProvider::class);
    $mock->shouldReceive('send')->times(3)->andReturn(ProviderResult::failed('500', 'server error'));
    fakeSmsProviderFactory($mock);

    // Tick 1 + 2 -> requeued; tick 3 -> failed.
    SendSmsJob::dispatchSync($msg->id);
    expect($msg->refresh()->status)->toBe(MessageStatus::Queued);

    SendSmsJob::dispatchSync($msg->id);
    expect($msg->refresh()->status)->toBe(MessageStatus::Queued);

    SendSmsJob::dispatchSync($msg->id);
    $msg->refresh();
    expect($msg->status)->toBe(MessageStatus::Failed)
        ->and($msg->attempt_count)->toBe(3)
        ->and($msg->error_message)->toBe('server error');
});

it('skips a reminder once work is completed', function () {
    $job = makeJobWithWelcome($this);
    // Appointment must exist so the reminder renders (needs {date_time}).
    $job->update(['scheduled_at' => now()->addDay()]);
    $msg = app(OutboxService::class)->queueForJob($job, TemplateKey::ReminderMorningOf, now()->subMinute());
    // Now complete the work — the reminder must be skipped at send time.
    $job->update(['work_completed_at' => now()]);

    SendSmsJob::dispatchSync($msg->id);

    expect($msg->refresh()->status)->toBe(MessageStatus::Skipped)
        ->and($msg->skip_reason)->toBe('work already completed');
});
