<?php

use App\Enums\JobStatus;
use App\Filament\Support\AiSchedulingTranscript;
use App\Jobs\ProcessAiSchedulingReply;
use App\Models\AiSchedulingAttempt;
use App\Models\AiSchedulingSession;
use App\Models\AiSchedulingTurn;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\InboundMessage;
use App\Models\Warehouse;
use App\Services\AiScheduling\AiSchedulingService;
use App\Services\AiScheduling\LlmClassifier;
use App\Services\AiScheduling\ReplyIntent;
use App\Services\AiScheduling\SchedulingConstraint;
use App\Services\Messaging\InboundMessageService;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    Carbon::setTestNow(Carbon::parse('2026-07-20 09:00', 'America/Los_Angeles'));

    $this->classifier = new FakeClassifier;
    $this->app->instance(LlmClassifier::class, $this->classifier);
    $this->service = app(AiSchedulingService::class);
    $this->inboundService = app(InboundMessageService::class);

    $this->entityId = Entity::where('code', 'LA')->value('id');
    $this->actor = Employee::create([
        'name' => 'Dispatch', 'email' => 'd3@test.local', 'entity_id' => $this->entityId,
        'job_functions' => ['office'], 'active' => true,
    ]);
    $this->jose = aiTech('Jose', $this->entityId, '+13105550001', priority: 1);
    $this->luis = aiTech('Luis', $this->entityId, '+13105550002', priority: 2);
    $this->warehouse = Warehouse::create(['name' => 'Main', 'entity_id' => $this->entityId]);
    $this->job = aiJob($this->entityId);
});

afterEach(fn () => Carbon::setTestNow());

/** Feed a message through the real webhook entry point. */
function inbound($test, string $from, string $body): InboundMessage
{
    return $test->inboundService->handle([
        'from_phone' => $from,
        'to_phone' => '+18185550000',
        'body' => $body,
        'received_at' => now()->toIso8601String(),
    ]);
}

/** A session the AI is allowed to text the customer on (startSession leaves that off). */
function customerSession($test, string $customerMode): AiSchedulingSession
{
    return $test->service->start(
        $test->job,
        SchedulingConstraint::window('2026-07-22', 'morning'),
        $test->actor,
        techMode: AiSchedulingSession::MODE_LIVE,
        customerMode: $customerMode,
        warehouseId: $test->warehouse->id,
    );
}

// -------------------------------------------------------- employee matching

it('resolves an inbound text from a technician to their employee record', function () {
    Queue::fake();

    $message = inbound($this, '+13105550001', 'hola');

    expect($message->employee_id)->toBe($this->jose->id)
        ->and($message->customer_id)->toBeNull();
});

it('matches a technician whose stored phone is not in E.164 form', function () {
    Queue::fake();
    $this->jose->update(['phone' => '(310) 555-0001']);

    $message = inbound($this, '+13105550001', 'hola');

    expect($message->employee_id)->toBe($this->jose->id);
});

it('still resolves a customer exactly as before', function () {
    Queue::fake();
    $customer = $this->job->customer;

    $message = inbound($this, $customer->phone_e164, 'hi there');

    expect($message->customer_id)->toBe($customer->id)
        ->and($message->employee_id)->toBeNull()
        ->and($customer->refresh()->last_inbound_at)->not->toBeNull();
});

// ---------------------------------------------------------------- opt-out

it('a customer STOP still opts them out, untouched by AI scheduling', function () {
    Queue::fake();
    $customer = $this->job->customer;

    inbound($this, $customer->phone_e164, 'STOP');

    expect($customer->refresh()->do_not_text)->toBeTrue();
});

it('a technician STOP turns OFF their AI scheduling opt-in and is never treated as a reply', function () {
    Queue::fake();
    $session = startSession($this);

    inbound($this, '+13105550001', 'STOP');

    expect($this->jose->refresh()->ai_scheduling_enabled)->toBeFalse();

    // Critically: not routed into the negotiation, so "STOP" can't be read as
    // an answer to the question we just asked them.
    Queue::assertNotPushed(ProcessAiSchedulingReply::class);

    $session->refresh();
    expect($session->attempts()->first()->status)->toBe(AiSchedulingAttempt::STATUS_ASKED);
});

it('a technician STOP leaves their operational texts alone', function () {
    Queue::fake();
    $this->jose->update(['eod_summary_texts_enabled' => true]);

    inbound($this, '+13105550001', 'STOP');

    expect($this->jose->refresh()->eod_summary_texts_enabled)->toBeTrue();
});

// ---------------------------------------------------------------- routing

it('queues the AI worker for a technician reply rather than doing it on the webhook', function () {
    Queue::fake();
    startSession($this);

    inbound($this, '+13105550001', 'si');

    Queue::assertPushed(ProcessAiSchedulingReply::class);
});

it('does not queue the worker for an unrelated number', function () {
    Queue::fake();

    inbound($this, '+15625559999', 'wrong number');

    Queue::assertNotPushed(ProcessAiSchedulingReply::class);
});

it('does not queue the worker for a customer with no live session', function () {
    Queue::fake();

    inbound($this, $this->job->customer->phone_e164, 'thanks!');

    Queue::assertNotPushed(ProcessAiSchedulingReply::class);
});

it('drives the whole negotiation end to end from an inbound webhook', function () {
    $session = startSession($this);

    // No Queue::fake() — the worker runs inline, as it would in production.
    // Twice: yes to the morning, then yes to the concrete slot we come back with.
    inbound($this, '+13105550001', 'si');
    inbound($this, '+13105550001', 'si');

    $this->job->refresh();
    expect($this->job->technician_id)->toBe($this->jose->id)
        ->and($this->job->status)->toBe(JobStatus::Scheduled);

    expect($session->refresh()->status)->toBe(AiSchedulingSession::STATUS_SCHEDULED);
});

it('routes a decline through the webhook and moves to the next tech', function () {
    $session = startSession($this);

    inbound($this, '+13105550001', 'no puedo');

    $session->refresh();
    expect($session->currentAttempt->employee_id)->toBe($this->luis->id);
});

// ------------------------------------------------- one-live-attempt-per-tech

it('attributes a reply to the tech\'s single live attempt', function () {
    $session = startSession($this);

    $inboundMsg = inbound($this, '+13105550001', 'si');

    $turn = $session->refresh()->turns()->where('inbound_message_id', $inboundMsg->id)->first();
    expect($turn)->not->toBeNull()
        ->and($turn->attempt_id)->toBe($session->attempts()->first()->id);
});

it('ignores a tech reply when they have no live attempt at all', function () {
    inbound($this, '+13105550001', 'si');

    expect(AiSchedulingSession::count())->toBe(0);
});

// ------------------------------------------------------- customer retarget

it('retargets a live session when the customer asks for a different day', function () {
    $session = startSession($this); // Wednesday the 22nd, morning
    $customer = $this->job->customer;

    $this->classifier->willAnswerCustomer(
        'can we do thursday afternoon instead?',
        ReplyIntent::fromLlmJson([
            'intent' => 'counter', 'date' => '2026-07-23', 'time' => '14:00',
            'confidence' => 0.9, 'reason' => 'wants thursday',
        ]),
    );

    inbound($this, $customer->phone_e164, 'can we do thursday afternoon instead?');

    $session->refresh();
    expect($session->retarget_count)->toBe(1)
        ->and($session->constraintObject()->date)->toBe('2026-07-23')
        ->and($session->constraintObject()->exactTime)->toBe('14:00');

    // The tech who was mid-conversation is released, and outreach restarts.
    $superseded = $session->attempts()->where('status', AiSchedulingAttempt::STATUS_SUPERSEDED)->count();
    expect($superseded)->toBe(1)
        ->and($session->currentAttempt->employee_id)->toBe($this->jose->id)
        ->and($session->currentAttempt->status)->toBe(AiSchedulingAttempt::STATUS_ASKED);
});

it('pauses for a human rather than guessing at an unreadable customer reply', function () {
    $session = customerSession($this, AiSchedulingSession::MODE_SIMULATE);

    inbound($this, $this->job->customer->phone_e164, 'wait what about the other thing');

    $session->refresh();
    expect($session->status)->toBe(AiSchedulingSession::STATUS_PAUSED)
        ->and($session->paused_reason)->toContain("could not read the customer's reply");
});

it('drops an unreadable customer reply entirely when customer texting is off', function () {
    $session = startSession($this); // customer mode defaults to off
    $before = $session->turns()->count();

    inbound($this, $this->job->customer->phone_e164, 'wait what about the other thing');

    $session->refresh();
    expect($session->status)->toBe(AiSchedulingSession::STATUS_RUNNING)
        ->and($session->paused_reason)->toBeNull()
        // Dispatch owns that thread by hand — nothing about it belongs on the
        // transcript: no customer bubble, no "leaving this to dispatch" note.
        ->and($session->turns()->count())->toBe($before);
});

it('still shows a customer reply it acted on when customer texting is off', function () {
    $session = startSession($this);
    $customer = $this->job->customer;

    $this->classifier->willAnswerCustomer('can we do thursday instead?', ReplyIntent::fromLlmJson([
        'intent' => 'counter', 'date' => '2026-07-23', 'confidence' => 0.9, 'reason' => 'wants thursday',
    ]));

    inbound($this, $customer->phone_e164, 'can we do thursday instead?');

    $session->refresh();
    expect($session->constraintObject()->date)->toBe('2026-07-23');

    $bubbles = AiSchedulingTranscript::bubbles($session);
    $customerBubbles = array_values(array_filter(
        $bubbles,
        fn ($b) => $b['party'] === 'customer' && $b['direction'] === 'in',
    ));

    expect($customerBubbles)->toHaveCount(1)
        ->and($customerBubbles[0]['body'])->toBe('can we do thursday instead?');
});

it('hides an unactionable customer bubble recorded before texting-off dropped them', function () {
    $session = startSession($this);

    // A row of the shape sessions carried before the service stopped writing them.
    AiSchedulingTurn::create([
        'session_id' => $session->id,
        'party' => 'customer',
        'direction' => 'in',
        'customer_id' => $this->job->customer_id,
        'body' => 'wait what about the other thing',
        'intent' => ReplyIntent::UNCLEAR,
        'confidence' => 0.0,
        'created_at' => now(),
    ]);

    $bubbles = AiSchedulingTranscript::bubbles($session);

    expect(array_filter($bubbles, fn ($b) => $b['party'] === 'customer'))->toBeEmpty();
});

it('stops retargeting once the customer has moved the appointment too many times', function () {
    config(['glass.ai_scheduling.max_retargets' => 1]);
    $session = customerSession($this, AiSchedulingSession::MODE_SIMULATE);
    $customer = $this->job->customer;

    $this->classifier->willAnswerCustomer('thursday', ReplyIntent::fromLlmJson([
        'intent' => 'counter', 'date' => '2026-07-23', 'confidence' => 0.9, 'reason' => 'x',
    ]));
    $this->classifier->willAnswerCustomer('actually friday', ReplyIntent::fromLlmJson([
        'intent' => 'counter', 'date' => '2026-07-24', 'confidence' => 0.9, 'reason' => 'x',
    ]));

    inbound($this, $customer->phone_e164, 'thursday');
    inbound($this, $customer->phone_e164, 'actually friday');

    $session->refresh();
    expect($session->status)->toBe(AiSchedulingSession::STATUS_PAUSED)
        ->and($session->paused_reason)->toContain('changed the time');
});

it('stops retargeting without pausing when customer texting is off', function () {
    config(['glass.ai_scheduling.max_retargets' => 1]);
    $session = startSession($this);
    $customer = $this->job->customer;

    $this->classifier->willAnswerCustomer('thursday', ReplyIntent::fromLlmJson([
        'intent' => 'counter', 'date' => '2026-07-23', 'confidence' => 0.9, 'reason' => 'x',
    ]));
    $this->classifier->willAnswerCustomer('actually friday', ReplyIntent::fromLlmJson([
        'intent' => 'counter', 'date' => '2026-07-24', 'confidence' => 0.9, 'reason' => 'x',
    ]));

    inbound($this, $customer->phone_e164, 'thursday');
    inbound($this, $customer->phone_e164, 'actually friday');

    $session->refresh();
    expect($session->status)->toBe(AiSchedulingSession::STATUS_RUNNING)
        // The second move is ignored, so the session keeps working the first one.
        ->and($session->constraintObject()->date)->toBe('2026-07-23')
        ->and($session->retarget_count)->toBe(1);
});

// ------------------------------------------------------------------- sweep

it('times out a silent tech on the sweep and asks the next one', function () {
    Queue::fake();
    $session = startSession($this);

    // Past the 90-minute response window (and still inside contact hours).
    Carbon::setTestNow(Carbon::now()->addMinutes(95));
    $result = $this->service->sweep();

    expect($result['advanced'])->toBe(1);
    expect($session->refresh()->currentAttempt->employee_id)->toBe($this->luis->id);
});

it('expires a session whose requested day has passed', function () {
    Queue::fake();
    $session = startSession($this); // targeting the 22nd

    Carbon::setTestNow(Carbon::parse('2026-07-23 09:00', 'America/Los_Angeles'));
    $result = $this->service->sweep();

    expect($result['expired'])->toBe(1);

    $session->refresh();
    expect($session->status)->toBe(AiSchedulingSession::STATUS_CANCELED)
        ->and($session->paused_reason)->toContain('has passed')
        ->and($session->attempts()->first()->status)->toBe(AiSchedulingAttempt::STATUS_SUPERSEDED);
});

// -------------------------------------------------- contact hours (6am-10:30pm)

it('reports the contact-hours boundaries the way the config describes them', function () {
    $at = fn (string $t) => Carbon::parse("2026-07-20 {$t}", 'America/Los_Angeles');

    expect(AiSchedulingService::outreachAllowedNow($at('05:59')))->toBeFalse()
        ->and(AiSchedulingService::outreachAllowedNow($at('06:00')))->toBeTrue()
        ->and(AiSchedulingService::outreachAllowedNow($at('22:29')))->toBeTrue()
        ->and(AiSchedulingService::outreachAllowedNow($at('22:30')))->toBeFalse()
        ->and(AiSchedulingService::outreachAllowedNow($at('23:30')))->toBeFalse();
});

it('holds a session started after 10:30pm instead of texting a tech, and says so on the transcript', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-07-20 23:00', 'America/Los_Angeles'));

    $session = $this->service->start(
        $this->job,
        SchedulingConstraint::window('2026-07-22', 'morning'),
        $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE,
        warehouseId: $this->warehouse->id,
    );

    expect($session->attempts()->count())->toBe(0)
        ->and($session->status)->toBe(AiSchedulingSession::STATUS_RUNNING)
        ->and($session->turns()->reorder()->latest('id')->value('body'))->toContain('Outside contact hours');

    // The sweep re-enters every two minutes all night; the note is written once.
    Carbon::setTestNow(Carbon::parse('2026-07-20 23:30', 'America/Los_Angeles'));
    $this->service->sweep();
    $this->service->sweep();

    expect($session->refresh()->turns()->where('body', 'like', 'Outside contact hours%')->count())->toBe(1)
        ->and($session->attempts()->count())->toBe(0);

    // 6am opens it back up.
    Carbon::setTestNow(Carbon::parse('2026-07-21 06:05', 'America/Los_Angeles'));
    $this->service->sweep();

    expect($session->refresh()->attempts()->count())->toBe(1)
        ->and($session->currentAttempt->employee_id)->toBe($this->jose->id);
});

it('does not time a silent tech out overnight — their clock waits for morning', function () {
    Queue::fake();
    $session = startSession($this); // 9am, Jose asked, 90-minute window

    // Deep into the night, hours past the deadline: nobody new gets a text and
    // Jose is not written off in his sleep either.
    Carbon::setTestNow(Carbon::parse('2026-07-20 23:45', 'America/Los_Angeles'));
    $this->service->sweep();

    expect($session->refresh()->attempts()->count())->toBe(1)
        ->and($session->attempts()->first()->status)->toBe(AiSchedulingAttempt::STATUS_ASKED);

    Carbon::setTestNow(Carbon::parse('2026-07-21 06:05', 'America/Los_Angeles'));
    $this->service->sweep();

    expect($session->refresh()->attempts()->count())->toBe(2)
        ->and($session->currentAttempt->employee_id)->toBe($this->luis->id);
});

it('still acts on a tech who replies after 10:30pm, but will not cold-text the next one', function () {
    Queue::fake();
    $session = startSession($this);

    Carbon::setTestNow(Carbon::parse('2026-07-20 23:15', 'America/Los_Angeles'));
    $this->service->simulateTechReply($session, $this->jose, 'no puedo');

    $session->refresh();

    // The reply was read and acted on at 11:15pm — only Luis's cold text waits.
    expect($session->attempts()->first()->status)->toBe(AiSchedulingAttempt::STATUS_DECLINED)
        ->and($session->attempts()->count())->toBe(1)
        ->and($session->status)->toBe(AiSchedulingSession::STATUS_RUNNING)
        ->and($session->turns()->reorder()->latest('id')->value('body'))->toContain('Outside contact hours');

    Carbon::setTestNow(Carbon::parse('2026-07-21 06:05', 'America/Los_Angeles'));
    $this->service->sweep();

    expect($session->refresh()->currentAttempt->employee_id)->toBe($this->luis->id);
});

it('waits for morning to book a slot the tech went silent on, but books it at once if they answer', function () {
    Queue::fake();
    $session = startSession($this);

    $this->service->simulateTechReply($session, $this->jose, 'si'); // -> confirming the 8am-10am slot

    // Confirm window long gone, but it is 11pm: silence books nothing.
    Carbon::setTestNow(Carbon::parse('2026-07-20 23:00', 'America/Los_Angeles'));
    $this->service->sweep();

    expect($this->job->refresh()->scheduled_at)->toBeNull()
        ->and($session->refresh()->attempts()->first()->status)
        ->toBe(AiSchedulingAttempt::STATUS_CONFIRMING_SLOT);

    // A reply at that same hour is a conversation, not the clock — book it.
    $this->service->simulateTechReply($session, $this->jose, 'si');

    expect($this->job->refresh()->technician_id)->toBe($this->jose->id)
        ->and($session->refresh()->status)->toBe(AiSchedulingSession::STATUS_SCHEDULED);
});

it('the sweep is a cheap no-op when nothing is running', function () {
    Queue::fake();

    expect($this->service->sweep())
        ->toBe(['advanced' => 0, 'expired' => 0, 'warehouse_asks_closed' => 0]);
});

it('runs from the console command', function () {
    Queue::fake();
    startSession($this);

    $this->artisan('ai:advance-sessions')->assertSuccessful();
});
