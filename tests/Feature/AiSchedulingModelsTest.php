<?php

use App\Models\AiSchedulingAttempt;
use App\Models\AiSchedulingSession;
use App\Models\AiSchedulingTurn;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\InboundMessage;
use App\Models\Job;
use App\Services\AiScheduling\SchedulingConstraint;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->dispatcher = Employee::create([
        'name' => 'Op', 'entity_id' => Entity::where('code', 'LA')->value('id'),
        'job_functions' => ['office'], 'active' => true,
    ]);
    $this->tech = Employee::create([
        'name' => 'Jose', 'entity_id' => Entity::where('code', 'LA')->value('id'),
        'job_functions' => ['technician'], 'active' => true, 'ai_scheduling_enabled' => true,
    ]);
    $customer = Customer::create(['phone_e164' => '+18185551234']);
    $this->job = Job::create([
        'job_number' => 'LA-2026-99001',
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customer->id,
    ]);
});

it('opts a technician into AI scheduling via the ai_scheduling_enabled column', function () {
    expect($this->tech->fresh()->ai_scheduling_enabled)->toBeTrue();

    $this->tech->update(['ai_scheduling_enabled' => false]);
    expect($this->tech->fresh()->ai_scheduling_enabled)->toBeFalse();
});

it('creates a session storing the constraint as json and reads it back as a SchedulingConstraint', function () {
    $constraint = SchedulingConstraint::window('2026-07-22', 'morning');

    $session = AiSchedulingSession::create([
        'job_id' => $this->job->id,
        'status' => AiSchedulingSession::STATUS_DRAFT,
        'constraint' => $constraint->toArray(),
        'original_constraint' => $constraint->toArray(),
        'tech_mode' => AiSchedulingSession::MODE_SIMULATE,
        'customer_mode' => AiSchedulingSession::MODE_OFF,
        'created_by' => $this->dispatcher->id,
    ]);

    $reloaded = $session->fresh();
    expect($reloaded->constraintObject()->toArray())->toBe($constraint->toArray())
        ->and($reloaded->job->id)->toBe($this->job->id)
        ->and($reloaded->createdBy->id)->toBe($this->dispatcher->id)
        ->and($reloaded->status)->toBe(AiSchedulingSession::STATUS_DRAFT)
        ->and($reloaded->isLive())->toBeFalse()
        ->and($reloaded->isClosed())->toBeFalse();
});

it('creates an attempt under a session and tracks the one-live-attempt-per-tech statuses', function () {
    $session = AiSchedulingSession::create([
        'job_id' => $this->job->id,
        'constraint' => SchedulingConstraint::flexible('2026-07-22')->toArray(),
        'original_constraint' => SchedulingConstraint::flexible('2026-07-22')->toArray(),
        'created_by' => $this->dispatcher->id,
    ]);

    $attempt = AiSchedulingAttempt::create([
        'session_id' => $session->id,
        'employee_id' => $this->tech->id,
        'position' => 1,
        'status' => AiSchedulingAttempt::STATUS_ASKED,
    ]);

    expect($attempt->fresh()->isLive())->toBeTrue()
        ->and($attempt->technician->id)->toBe($this->tech->id)
        ->and($session->attempts()->count())->toBe(1);

    $attempt->update(['status' => AiSchedulingAttempt::STATUS_ACCEPTED]);
    expect($attempt->fresh()->isLive())->toBeFalse();
});

it('records a transcript turn linked to a session, attempt, and inbound message', function () {
    $session = AiSchedulingSession::create([
        'job_id' => $this->job->id,
        'constraint' => SchedulingConstraint::flexible('2026-07-22')->toArray(),
        'original_constraint' => SchedulingConstraint::flexible('2026-07-22')->toArray(),
        'created_by' => $this->dispatcher->id,
    ]);
    $attempt = AiSchedulingAttempt::create([
        'session_id' => $session->id, 'employee_id' => $this->tech->id, 'position' => 1,
    ]);
    $inbound = InboundMessage::create([
        'employee_id' => $this->tech->id,
        'from_phone_e164' => '+13105550001',
        'to_phone_e164' => '+18185550000',
        'body' => 'si puedo',
        'received_at' => now(),
    ]);

    $turn = AiSchedulingTurn::create([
        'session_id' => $session->id,
        'attempt_id' => $attempt->id,
        'party' => AiSchedulingTurn::PARTY_TECH,
        'direction' => AiSchedulingTurn::DIRECTION_IN,
        'employee_id' => $this->tech->id,
        'inbound_message_id' => $inbound->id,
        'body' => 'si puedo',
        'intent' => 'accept',
        'confidence' => 0.95,
        'used_llm' => false,
    ]);

    expect($turn->fresh())
        ->session->id->toBe($session->id)
        ->and($turn->attempt->id)->toBe($attempt->id)
        ->and($turn->inboundMessage->id)->toBe($inbound->id)
        ->and($turn->confidence)->toBe(0.95)
        ->and($session->turns()->count())->toBe(1);
});

it('routes an inbound message to an employee via the new employee_id column', function () {
    $inbound = InboundMessage::create([
        'employee_id' => $this->tech->id,
        'from_phone_e164' => '+13105550001',
        'to_phone_e164' => '+18185550000',
        'body' => 'no puedo',
        'received_at' => now(),
    ]);

    expect($inbound->employee->id)->toBe($this->tech->id)
        ->and($inbound->customer)->toBeNull();
});
