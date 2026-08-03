<?php

use App\Enums\JobStatus;
use App\Models\AiSchedulingAttempt;
use App\Models\AiSchedulingSession;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\Warehouse;
use App\Services\AiScheduling\AiSchedulingService;
use App\Services\AiScheduling\Commit;
use App\Services\AiScheduling\LlmClassifier;
use App\Services\AiScheduling\SchedulingConstraint;
use App\Services\JobLifecycle;
use App\Support\Timezone;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Queue;

/**
 * Commit is the only code in AI scheduling that writes to a Job, so its
 * refusals matter more than its successes. Each of these is a race a real
 * session can lose: minutes pass between texting a technician and reading
 * their "yes", and the world moves in that window.
 */
beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    Carbon::setTestNow(Carbon::parse('2026-07-20 09:00', 'America/Los_Angeles'));

    $this->app->instance(LlmClassifier::class, new FakeClassifier);
    $this->service = app(AiSchedulingService::class);
    $this->commit = app(Commit::class);

    $this->entityId = Entity::where('code', 'LA')->value('id');
    $this->actor = Employee::create([
        'name' => 'Dispatch', 'email' => 'd2@test.local', 'entity_id' => $this->entityId,
        'job_functions' => ['office'], 'active' => true,
    ]);
    $this->jose = aiTech('Jose', $this->entityId, '+13105550001', priority: 1);
    $this->warehouse = Warehouse::create(['name' => 'Main', 'entity_id' => $this->entityId]);
    $this->job = aiJob($this->entityId);

    $this->constraint = SchedulingConstraint::window('2026-07-22', 'morning');
    $this->session = $this->service->start(
        $this->job, $this->constraint, $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE, warehouseId: $this->warehouse->id,
    );
    $this->attempt = $this->session->attempts()->first();
});

afterEach(fn () => Carbon::setTestNow());

it('commits cleanly when nothing has changed', function () {
    expect($this->commit->execute($this->session, $this->attempt, '09:00'))->toBeNull();

    $this->job->refresh();
    expect($this->job->technician_id)->toBe($this->jose->id)
        ->and(Timezone::toDisplay($this->job->scheduled_at)->format('Y-m-d H:i'))->toBe('2026-07-22 09:00');
});

it('REFUSES when a dispatcher scheduled the job by hand in the meantime', function () {
    app(JobLifecycle::class)->schedule(
        $this->job,
        Carbon::parse('2026-07-23 13:00', Timezone::display())->utc(),
        $this->actor,
        technicianId: $this->jose->id,
        warehouseId: $this->warehouse->id,
    );

    $refusal = $this->commit->execute($this->session, $this->attempt->refresh(), '09:00');

    expect($refusal)->toContain('already has a scheduled time');

    // The human's booking survives untouched.
    $this->job->refresh();
    expect(Timezone::toDisplay($this->job->scheduled_at)->format('Y-m-d H:i'))->toBe('2026-07-23 13:00');
});

it('REFUSES when a technician was assigned outside the session', function () {
    $other = aiTech('Luis', $this->entityId, '+13105550002');
    $this->job->update(['technician_id' => $other->id]);

    $refusal = $this->commit->execute($this->session, $this->attempt, '09:00');

    expect($refusal)->toContain('already has a technician');
    expect($this->job->refresh()->technician_id)->toBe($other->id);
});

it('REFUSES when the job is no longer awaiting scheduling', function () {
    $this->job->update(['status' => JobStatus::Canceled->value]);

    expect($this->commit->execute($this->session, $this->attempt, '09:00'))
        ->toContain('no longer awaiting scheduling');
});

it('REFUSES when the session is no longer running', function () {
    $this->service->pause($this->session, 'a human took over');

    expect($this->commit->execute($this->session->refresh(), $this->attempt, '09:00'))
        ->toContain('not running');

    expect($this->job->refresh()->scheduled_at)->toBeNull();
});

it('REFUSES when the technician has been deactivated', function () {
    $this->jose->update(['active' => false]);

    expect($this->commit->execute($this->session, $this->attempt, '09:00'))
        ->toContain('no longer active');
});

it('REFUSES when the technician has since opted out of AI scheduling', function () {
    $this->jose->update(['ai_scheduling_enabled' => false]);

    expect($this->commit->execute($this->session, $this->attempt, '09:00'))
        ->toContain('opted out');
});

it('REFUSES a time outside the customers own window', function () {
    expect($this->commit->execute($this->session, $this->attempt, '15:00'))
        ->toContain('outside the customer');

    expect($this->job->refresh()->scheduled_at)->toBeNull();
});

it('REFUSES a time that has already passed', function () {
    $session = AiSchedulingSession::create([
        'job_id' => aiJob($this->entityId)->id,
        'status' => AiSchedulingSession::STATUS_RUNNING,
        'constraint' => SchedulingConstraint::window('2026-07-19', 'morning')->toArray(),
        'original_constraint' => SchedulingConstraint::window('2026-07-19', 'morning')->toArray(),
        'tech_mode' => AiSchedulingSession::MODE_LIVE,
        'created_by' => $this->actor->id,
    ]);
    $attempt = AiSchedulingAttempt::create([
        'session_id' => $session->id, 'employee_id' => $this->jose->id,
        'position' => 1, 'status' => AiSchedulingAttempt::STATUS_ASKED,
    ]);

    expect($this->commit->execute($session, $attempt, '09:00'))->toContain('in the past');
});

it('REFUSES when the tech picked up a conflicting exact-time job while we waited', function () {
    $session = AiSchedulingSession::create([
        'job_id' => aiJob($this->entityId)->id,
        'status' => AiSchedulingSession::STATUS_RUNNING,
        'constraint' => SchedulingConstraint::exact('2026-07-22', '10:00')->toArray(),
        'original_constraint' => SchedulingConstraint::exact('2026-07-22', '10:00')->toArray(),
        'tech_mode' => AiSchedulingSession::MODE_LIVE,
        'created_by' => $this->actor->id,
    ]);
    $attempt = AiSchedulingAttempt::create([
        'session_id' => $session->id, 'employee_id' => $this->jose->id,
        'position' => 1, 'status' => AiSchedulingAttempt::STATUS_ASKED,
    ]);

    // Jose picks up an exact-time 9-11am job elsewhere.
    Job::create([
        'job_number' => 'LA-2026-77777',
        'entity_id' => $this->entityId,
        'customer_id' => Customer::create(['phone_e164' => '+18185559999'])->id,
        'technician_id' => $this->jose->id,
        'status' => JobStatus::Scheduled->value,
        'scheduled_at' => Carbon::parse('2026-07-22 09:00', Timezone::display())->utc(),
        'scheduled_exact_time' => true,
    ]);

    expect($this->commit->execute($session, $attempt, '10:00'))->toContain('conflicting job');
});

it('escalates the session instead of scheduling when a commit is refused mid-negotiation', function () {
    // A human books it out from under the live negotiation, then the tech says yes.
    app(JobLifecycle::class)->schedule(
        $this->job,
        Carbon::parse('2026-07-23 13:00', Timezone::display())->utc(),
        $this->actor,
        technicianId: $this->jose->id,
        warehouseId: $this->warehouse->id,
    );

    // Yes to the morning, then yes to the slot we propose — the commit (and
    // its refusal) only happens on that second reply.
    $this->service->simulateTechReply($this->session, $this->jose, 'si');
    $this->service->simulateTechReply($this->session, $this->jose, 'si');

    $this->session->refresh();
    expect($this->session->status)->toBe(AiSchedulingSession::STATUS_PAUSED)
        ->and($this->session->paused_reason)->toContain('could not be scheduled');

    $this->job->refresh();
    expect(Timezone::toDisplay($this->job->scheduled_at)->format('Y-m-d H:i'))->toBe('2026-07-23 13:00');
});
