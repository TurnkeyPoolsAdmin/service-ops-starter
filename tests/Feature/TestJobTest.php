<?php

use App\Enums\JobStatus;
use App\Enums\TemplateKey;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Issue;
use App\Models\Job;
use App\Services\JobLifecycle;
use App\Services\Messaging\OutboxService;
use App\Services\TestJobFactory;
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
        'name' => 'Tess Tester', 'email' => 'tess@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');
});

it('creates a pending-schedule job with placeholder data and the fixed test phone', function () {
    $job = app(TestJobFactory::class)->create($this->actor);

    expect($job->is_test)->toBeTrue()
        ->and($job->status)->toBe(JobStatus::PendingSchedule)
        ->and($job->customer_phone_snapshot)->toBe('+15555550142')
        ->and($job->customer_name_snapshot)->toBe('Test Placeholder')
        ->and($job->entity->code)->toBe('LA')
        ->and($job->quote_amount_cents)->toBe(30000);
});

it('is textable like any other job - it is not opted out or suppressed', function () {
    $job = app(TestJobFactory::class)->create($this->actor);

    // The whole point of a test job is that the texts really go out, so the
    // customer must look ordinary to OutboxService's opt-out gate.
    expect($job->customer->do_not_text)->toBeFalsy();

    $message = app(OutboxService::class)->queueForJob($job, TemplateKey::WelcomeDispatchIntro, null, $this->actor->id);

    expect($message)->not->toBeNull()
        ->and($message->to_phone_e164)->toBe('+15555550142');
});

it('reuses the same customer across repeat clicks', function () {
    $factory = app(TestJobFactory::class);

    $first = $factory->create($this->actor);
    $second = $factory->create($this->actor);

    expect($second->id)->not->toBe($first->id)
        ->and($second->customer_id)->toBe($first->customer_id);
});

it('purges a deleted test job without raising an issue', function () {
    $job = app(TestJobFactory::class)->create($this->actor);

    app(JobLifecycle::class)->deleteCompletely($job, $this->actor);

    expect(Job::withTrashed()->find($job->id))->toBeNull()
        ->and(Issue::query()->count())->toBe(0);
});

it('still raises an issue when a real job is deleted', function () {
    $job = app(JobLifecycle::class)->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185550101',
        'first_name' => 'Real',
        'last_name' => 'Customer',
    ], $this->actor);

    app(JobLifecycle::class)->deleteCompletely($job, $this->actor);

    expect(Job::withTrashed()->find($job->id))->not->toBeNull()
        ->and(Issue::query()->count())->toBe(1);
});
