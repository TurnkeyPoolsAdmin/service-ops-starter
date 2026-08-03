<?php

use App\Enums\JobStatus;
use App\Filament\Pages\AiScheduling;
use App\Filament\Pages\SendWelcome;
use App\Filament\Resources\Jobs\Pages\ViewJob;
use App\Filament\Resources\Jobs\Schemas\JobForm;
use App\Filament\Support\AiSchedulingTranscript;
use App\Models\AiSchedulingAttempt;
use App\Models\AiSchedulingSession;
use App\Models\AiSchedulingTurn;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\ScheduledMessage;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\AiScheduling\AiSchedulingService;
use App\Services\AiScheduling\LlmClassifier;
use App\Services\AiScheduling\ReplyIntent;
use App\Services\AiScheduling\SchedulingConstraint;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    Carbon::setTestNow(Carbon::parse('2026-07-20 09:00', 'America/Los_Angeles'));

    $this->app->instance(LlmClassifier::class, new FakeClassifier);

    $this->entityId = Entity::where('code', 'LA')->value('id');
    $this->actor = Employee::create([
        'name' => 'Dispatch', 'email' => 'ui-actor@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'], 'entity_id' => $this->entityId,
    ]);
    $this->actor->assignRole('super_admin');
    $this->actingAs($this->actor);

    $this->jose = aiTech('Jose', $this->entityId, '+13105550001', priority: 1);
    $this->warehouse = Warehouse::create(['name' => 'Main', 'entity_id' => $this->entityId]);
    $this->job = aiJob($this->entityId);

    Setting::put('ai_scheduling_enabled', '1', sensitive: false);
});

afterEach(fn () => Carbon::setTestNow());

// ---------------------------------------------------- launch action gating

it('hides "Schedule w/ AI" until the global switch is on', function () {
    Setting::put('ai_scheduling_enabled', '0', sensitive: false);

    Livewire::test(ViewJob::class, ['record' => $this->job->getRouteKey()])
        ->assertActionHidden('scheduleWithAi');
});

it('hides "Schedule w/ AI" when no tech at the location has opted in', function () {
    $this->jose->update(['ai_scheduling_enabled' => false]);

    Livewire::test(ViewJob::class, ['record' => $this->job->getRouteKey()])
        ->assertActionHidden('scheduleWithAi');
});

it('hides "Schedule w/ AI" once the job is already scheduled', function () {
    $this->job->update(['status' => JobStatus::Scheduled->value, 'technician_id' => $this->jose->id]);

    Livewire::test(ViewJob::class, ['record' => $this->job->getRouteKey()])
        ->assertActionHidden('scheduleWithAi');
});

it('hides "Schedule w/ AI" when a session is already live for the job', function () {
    app(AiSchedulingService::class)->start($this->job, SchedulingConstraint::flexible('2026-07-22'), $this->actor);

    Livewire::test(ViewJob::class, ['record' => $this->job->getRouteKey()])
        ->assertActionHidden('scheduleWithAi');
});

it('shows "Schedule w/ AI" once every condition is met', function () {
    Livewire::test(ViewJob::class, ['record' => $this->job->getRouteKey()])
        ->assertActionVisible('scheduleWithAi');
});

// -------------------------------------------------------------- launch flow

it('starts a live session from the job view and redirects to the AI Scheduling page', function () {
    Livewire::test(ViewJob::class, ['record' => $this->job->getRouteKey()])
        ->callAction('scheduleWithAi', data: [
            'ai_date' => '2026-07-22',
            'ai_firmness' => SchedulingConstraint::FIRM_WINDOW,
            'ai_part_of_day' => 'morning',
            'warehouse_id' => $this->warehouse->id,
            'tech_mode' => AiSchedulingSession::MODE_SIMULATE,
            'customer_mode' => AiSchedulingSession::MODE_OFF,
        ])
        ->assertRedirect();

    $session = AiSchedulingSession::where('job_id', $this->job->id)->first();
    expect($session)->not->toBeNull()
        ->and($session->status)->toBe(AiSchedulingSession::STATUS_RUNNING)
        ->and($session->tech_mode)->toBe(AiSchedulingSession::MODE_SIMULATE)
        ->and($session->warehouse_id)->toBe($this->warehouse->id)
        ->and($session->constraintObject()->date)->toBe('2026-07-22')
        ->and($session->attempts()->first()->employee_id)->toBe($this->jose->id);
});

// The modal's contact list is a drag-to-reorder repeater whose default is the
// pool in roster-ranking order; dragging a row just reorders that list.
it('defaults the contact list to the opted-in techs in roster-ranking order', function () {
    $luis = aiTech('Luis', $this->entityId, '+13105550002', priority: 2);
    aiTech('Not opted in', $this->entityId, '+13105550003', priority: 3, optedIn: false);

    $component = Livewire::test(ViewJob::class, ['record' => $this->job->getRouteKey()])
        ->mountAction('scheduleWithAi');

    // Repeater rows are uuid-keyed in the live form state; the ORDER of the
    // rows is the contact order, which is what this is asserting.
    $rows = array_values($component->get('mountedActions.0.data.tech_order'));

    expect(array_column($rows, 'employee_id'))->toBe([$this->jose->id, $luis->id]);
});

it('starts the session with the contact order the dispatcher left the list in', function () {
    $luis = aiTech('Luis', $this->entityId, '+13105550002', priority: 2);

    $component = Livewire::test(ViewJob::class, ['record' => $this->job->getRouteKey()])
        ->mountAction('scheduleWithAi');

    // Rows are uuid-keyed, so "dragging Luis to the top" is the values of the
    // first and second rows swapping over — the row ORDER is the contact order.
    $rowKeys = array_keys($component->get('mountedActions.0.data.tech_order'));

    $component
        ->setActionData([
            'ai_date' => '2026-07-22',
            'ai_firmness' => SchedulingConstraint::FIRM_FLEXIBLE,
            'tech_order' => [
                $rowKeys[0] => ['employee_id' => $luis->id],
                $rowKeys[1] => ['employee_id' => $this->jose->id],
            ],
            'tech_mode' => AiSchedulingSession::MODE_SIMULATE,
            'customer_mode' => AiSchedulingSession::MODE_OFF,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $session = AiSchedulingSession::where('job_id', $this->job->id)->first();
    expect($session->techOrder())->toBe([$luis->id, $this->jose->id])
        ->and($session->attempts()->first()->employee_id)->toBe($luis->id);
});

it('builds an exact-time constraint from the form when firmness is exact', function () {
    Livewire::test(ViewJob::class, ['record' => $this->job->getRouteKey()])
        ->callAction('scheduleWithAi', data: [
            'ai_date' => '2026-07-22',
            'ai_firmness' => SchedulingConstraint::FIRM_EXACT,
            'ai_time' => '14:00',
            'warehouse_id' => $this->warehouse->id,
            'tech_mode' => AiSchedulingSession::MODE_SIMULATE,
            'customer_mode' => AiSchedulingSession::MODE_OFF,
        ]);

    $session = AiSchedulingSession::where('job_id', $this->job->id)->first();
    expect($session->constraintObject()->firmness)->toBe(SchedulingConstraint::FIRM_EXACT)
        ->and($session->constraintObject()->exactTime)->toBe('14:00');
});

it('maps the "Other" warehouse sentinel to warehouse_other, same as the normal schedule action', function () {
    Livewire::test(ViewJob::class, ['record' => $this->job->getRouteKey()])
        ->callAction('scheduleWithAi', data: [
            'ai_date' => '2026-07-22',
            'ai_firmness' => SchedulingConstraint::FIRM_FLEXIBLE,
            'warehouse_id' => JobForm::WAREHOUSE_OTHER,
            'warehouse_other' => 'Dealer down the street',
            'tech_mode' => AiSchedulingSession::MODE_SIMULATE,
            'customer_mode' => AiSchedulingSession::MODE_OFF,
        ]);

    $session = AiSchedulingSession::where('job_id', $this->job->id)->first();
    expect($session->warehouse_id)->toBeNull()
        ->and($session->warehouse_other)->toBe('Dealer down the street');
});

it('starts a session with no warehouse chosen — unlike manual scheduling, it is not required here', function () {
    Livewire::test(ViewJob::class, ['record' => $this->job->getRouteKey()])
        ->callAction('scheduleWithAi', data: [
            'ai_date' => '2026-07-22',
            'ai_firmness' => SchedulingConstraint::FIRM_FLEXIBLE,
            'tech_mode' => AiSchedulingSession::MODE_SIMULATE,
            'customer_mode' => AiSchedulingSession::MODE_OFF,
        ])
        ->assertHasNoActionErrors();

    $session = AiSchedulingSession::where('job_id', $this->job->id)->first();
    expect($session)->not->toBeNull()
        ->and($session->warehouse_id)->toBeNull()
        ->and($session->warehouse_other)->toBeNull();
});

// -------------------------------------------------------------- SendWelcome

it('offers "Schedule w/ AI" from the post-welcome screen once conditions are met', function () {
    // Mirrors the welcomeAlreadyQueued() branch: queue a welcome row first.
    ScheduledMessage::create([
        'job_id' => $this->job->id, 'customer_id' => $this->job->customer_id,
        'entity_id' => $this->entityId, 'template_key' => 'welcome_generic',
        'message_type' => 'welcome', 'to_phone_e164' => '+18185551234',
        'from_phone_e164' => '+18185550000', 'rendered_body' => 'hi', 'status' => 'sent',
        'idempotency_key' => 'test:'.$this->job->id.':welcome',
    ]);

    Livewire::test(SendWelcome::class, ['job' => $this->job->id])
        ->assertActionVisible('scheduleWithAi');
});

// -------------------------------------------------------- AI Scheduling page

it('lists sessions and opens a transcript on request', function () {
    $session = app(AiSchedulingService::class)->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE, warehouseId: $this->warehouse->id,
    );

    Livewire::test(AiScheduling::class)
        ->assertSeeText($this->job->job_number)
        ->set('selected', $session->id)
        ->assertSeeText('Session #'.$session->id)
        ->assertSeeText('Wednesday, July 22');
});

it('pauses and resumes the selected session', function () {
    $session = app(AiSchedulingService::class)->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE, warehouseId: $this->warehouse->id,
    );

    Livewire::test(AiScheduling::class)
        ->set('selected', $session->id)
        ->callAction('pauseSession', data: ['reason' => 'checking with the customer']);

    expect($session->refresh()->status)->toBe(AiSchedulingSession::STATUS_PAUSED)
        ->and($session->paused_reason)->toBe('checking with the customer');

    Livewire::test(AiScheduling::class)
        ->set('selected', $session->id)
        ->callAction('resumeSession');

    expect($session->refresh()->status)->toBe(AiSchedulingSession::STATUS_RUNNING);
});

it('skips the current tech from the page', function () {
    $luis = aiTech('Luis', $this->entityId, '+13105550002', priority: 2);
    $session = app(AiSchedulingService::class)->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE, warehouseId: $this->warehouse->id,
    );

    Livewire::test(AiScheduling::class)
        ->set('selected', $session->id)
        ->callAction('skipTech', data: ['reason' => 'called out']);

    $session->refresh();
    expect($session->attempts()->where('employee_id', $this->jose->id)->value('status'))
        ->toBe(AiSchedulingAttempt::STATUS_SKIPPED)
        ->and($session->currentAttempt->employee_id)->toBe($luis->id);
});

it('cancels the selected session', function () {
    $session = app(AiSchedulingService::class)->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE, warehouseId: $this->warehouse->id,
    );

    Livewire::test(AiScheduling::class)
        ->set('selected', $session->id)
        ->callAction('cancelSession', data: ['reason' => 'customer canceled the job']);

    expect($session->refresh()->status)->toBe(AiSchedulingSession::STATUS_CANCELED);
});

it('takes over: cancels the session and redirects to the job', function () {
    $session = app(AiSchedulingService::class)->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE, warehouseId: $this->warehouse->id,
    );

    Livewire::test(AiScheduling::class)
        ->set('selected', $session->id)
        ->callAction('takeOver')
        ->assertRedirect();

    expect($session->refresh()->status)->toBe(AiSchedulingSession::STATUS_CANCELED);
});

it('runs a simulated tech reply from the console and shows it in the transcript', function () {
    $session = app(AiSchedulingService::class)->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->actor,
        techMode: AiSchedulingSession::MODE_SIMULATE, warehouseId: $this->warehouse->id,
    );

    $component = Livewire::test(AiScheduling::class)->set('selected', $session->id);

    expect($component->instance()->simulateParticipantOptions())->toHaveKey('tech:'.$this->jose->id);

    // Yes to the morning, then yes to the concrete slot it proposes back.
    foreach (['si', 'si'] as $reply) {
        $component->callAction('simulateReply', data: [
            'participant' => 'tech:'.$this->jose->id,
            'body' => $reply,
        ]);
    }

    $session->refresh();
    expect($session->status)->toBe(AiSchedulingSession::STATUS_SIMULATED)
        ->and($session->resolved_technician_id)->toBe($this->jose->id);

    $this->job->refresh();
    expect($this->job->technician_id)->toBeNull(); // simulate never touches the real job

    $bubbles = AiSchedulingTranscript::bubbles($session);
    expect(collect($bubbles)->pluck('body'))->toContain('si');
});

it('runs a simulated customer reply from the console', function () {
    $session = app(AiSchedulingService::class)->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE, customerMode: AiSchedulingSession::MODE_SIMULATE,
        warehouseId: $this->warehouse->id,
    );

    $classifier = app(LlmClassifier::class);
    $classifier->willAnswerCustomer('actually thursday', ReplyIntent::fromLlmJson([
        'intent' => 'counter', 'date' => '2026-07-23', 'confidence' => 0.9, 'reason' => 'wants thursday',
    ]));

    Livewire::test(AiScheduling::class)
        ->set('selected', $session->id)
        ->callAction('simulateReply', data: [
            'participant' => 'customer:'.$this->job->customer_id,
            'body' => 'actually thursday',
        ]);

    expect($session->refresh()->retarget_count)->toBe(1)
        ->and($session->constraintObject()->date)->toBe('2026-07-23');
});

it('approves a parked outbound in approve mode from the page', function () {
    $session = app(AiSchedulingService::class)->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->actor,
        techMode: AiSchedulingSession::MODE_APPROVE, warehouseId: $this->warehouse->id,
    );

    $pending = $session->turns()->where('approval_status', AiSchedulingTurn::APPROVAL_PENDING)->first();
    expect($pending)->not->toBeNull();

    Livewire::test(AiScheduling::class)
        ->set('selected', $session->id)
        ->callAction(TestAction::make('approveTurn'), arguments: ['turn' => $pending->id]);

    expect($pending->refresh()->approval_status)->toBe(AiSchedulingTurn::APPROVAL_APPROVED)
        ->and(ScheduledMessage::where('employee_id', $this->jose->id)->count())->toBe(1);
});

it('skips a parked outbound in approve mode from the page', function () {
    $session = app(AiSchedulingService::class)->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->actor,
        techMode: AiSchedulingSession::MODE_APPROVE, warehouseId: $this->warehouse->id,
    );

    $pending = $session->turns()->where('approval_status', AiSchedulingTurn::APPROVAL_PENDING)->first();

    Livewire::test(AiScheduling::class)
        ->set('selected', $session->id)
        ->callAction(TestAction::make('skipTurn'), arguments: ['turn' => $pending->id]);

    expect($pending->refresh()->approval_status)->toBe(AiSchedulingTurn::APPROVAL_SKIPPED)
        ->and(ScheduledMessage::count())->toBe(0);
});

// ------------------------------------------------------------- transcript

it('builds bubbles in party/direction order with pending flags', function () {
    $session = app(AiSchedulingService::class)->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->actor,
        techMode: AiSchedulingSession::MODE_APPROVE, warehouseId: $this->warehouse->id,
    );

    $bubbles = AiSchedulingTranscript::bubbles($session);

    expect($bubbles)->not->toBeEmpty();
    $outbound = collect($bubbles)->firstWhere('direction', 'out');
    expect($outbound['party'])->toBe('tech')
        ->and($outbound['who'])->toBe('Jose')
        ->and($outbound['is_pending'])->toBeTrue()
        ->and($outbound['pending_template'])->toBe('tech_ai_slot_offer');
});
