<?php

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\TemplateKey;
use App\Jobs\SendSmsJob;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
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
    ])->assignRole('super_admin');
    $this->tech = Employee::create([
        'name' => 'Tech Tina', 'active' => true, 'job_functions' => ['technician'],
        'password' => bcrypt('x'),
    ]);
    $this->lifecycle = app(JobLifecycle::class);
});

afterEach(fn () => Carbon::setTestNow());

function pauseJob($test): Job
{
    return $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Pat', 'quote_amount_cents' => 50000,
    ], $test->actor);
}

it('queues NO automated texts for a paused lead (reminders + confirmation)', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-20 12:00', 'America/Los_Angeles'));
    $job = pauseJob($this);
    $job->customer->update(['auto_text_paused' => true]);

    // Schedule two days out WITH a technician — normally this queues two reminders
    // AND the time confirmation. Paused → none of them are created.
    $this->lifecycle->schedule(
        $job,
        Carbon::parse('2026-06-25 14:00', 'America/Los_Angeles')->utc(),
        $this->actor,
        technicianId: $this->tech->id,
    );

    $job->refresh();
    expect($job->scheduledMessages()->where('message_type', MessageType::Reminder->value)->count())->toBe(0)
        ->and($job->scheduledMessages()->where('message_type', MessageType::Confirmation->value)->count())->toBe(0);
});

it('still lets a dispatcher send a MANUAL text to a paused lead', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-20 12:00', 'America/Los_Angeles'));
    $job = pauseJob($this);
    $job->customer->update(['auto_text_paused' => true]);

    $this->lifecycle->schedule(
        $job,
        Carbon::parse('2026-06-25 14:00', 'America/Los_Angeles')->utc(),
        $this->actor,
        technicianId: $this->tech->id,
    );

    // Manual send goes through queueManual() — pause does not apply. It is also
    // dispatched immediately, and in tests the log provider "sends" it.
    $message = $this->lifecycle->sendManualMessage($job, TemplateKey::TimeConfirmation, $this->actor);

    expect($message)->not->toBeNull()
        ->and($message->message_type)->toBe(MessageType::Manual)
        ->and($message->refresh()->status)->toBe(MessageStatus::Sent);
});

it('skips an automated row queued BEFORE the pause, but never a manual one', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-20 12:00', 'America/Los_Angeles'));
    $job = pauseJob($this);

    // Not paused yet: scheduling queues reminders (awaiting the cron).
    $this->lifecycle->schedule(
        $job,
        Carbon::parse('2026-06-25 14:00', 'America/Los_Angeles')->utc(),
        $this->actor,
        technicianId: $this->tech->id,
    );
    $reminder = $job->refresh()->scheduledMessages()
        ->where('message_type', MessageType::Reminder->value)->first();
    expect($reminder)->not->toBeNull();

    // Now pause, then run the worker on that already-queued reminder.
    $job->customer->update(['auto_text_paused' => true]);
    SendSmsJob::dispatchSync($reminder->id);

    expect($reminder->refresh()->status)->toBe(MessageStatus::Skipped)
        ->and($reminder->skip_reason)->toBe('auto-texts paused for lead');

    // A manual row for the same paused lead still sends.
    $manual = $this->lifecycle->sendManualMessage($job, TemplateKey::TimeConfirmation, $this->actor);
    expect($manual->refresh()->status)->toBe(MessageStatus::Sent);
});
