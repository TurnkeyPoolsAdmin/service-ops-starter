<?php

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\TemplateKey;
use App\Jobs\SendSmsJob;
use App\Models\Employee;
use App\Models\Entity;
use App\Services\JobLifecycle;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ])->assignRole('super_admin');

    $this->actingAs($this->actor);
    $this->lifecycle = app(JobLifecycle::class);
});

function manualJob($test)
{
    return $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Al', 'quote_amount_cents' => 50000,
    ], $test->actor);
}

it('sendManualMessage queues a manual-typed row, sets the provider, and dispatches it', function () {
    Queue::fake();
    $job = manualJob($this);

    $msg = $this->lifecycle->sendManualMessage($job, TemplateKey::WelcomeDispatchIntro, $this->actor);

    expect($msg)->not->toBeNull()
        ->and($msg->message_type)->toBe(MessageType::Manual)
        ->and($msg->template_key)->toBe(TemplateKey::WelcomeDispatchIntro)
        ->and($msg->provider)->toBe('openphone')          // from preferredProvider()
        ->and($msg->status)->toBe(MessageStatus::Queued);

    Queue::assertPushed(SendSmsJob::class);
});

it('manual sends are repeatable — each call creates a new row', function () {
    Queue::fake();
    $job = manualJob($this);

    $this->lifecycle->sendManualMessage($job, TemplateKey::WelcomeGeneric, $this->actor);
    $this->lifecycle->sendManualMessage($job, TemplateKey::WelcomeGeneric, $this->actor);

    expect($job->scheduledMessages()->where('template_key', 'welcome_generic')->count())->toBe(2);
});

it('refuses to send to an opted-out customer', function () {
    Queue::fake();
    $job = manualJob($this);
    $job->customer->update(['do_not_text' => true]);

    $msg = $this->lifecycle->sendManualMessage($job, TemplateKey::WelcomeGeneric, $this->actor);

    expect($msg)->toBeNull();
    Queue::assertNothingPushed();
});
