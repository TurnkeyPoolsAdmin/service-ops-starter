<?php

use App\Enums\JobStatus;
use App\Enums\TemplateKey;
use App\Models\AiSchedulingAttempt;
use App\Models\AiSchedulingSession;
use App\Models\AiSchedulingTurn;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\ScheduledMessage;
use App\Models\Warehouse;
use App\Services\AiScheduling\AiSchedulingService;
use App\Services\AiScheduling\LlmClassifier;
use App\Services\AiScheduling\ReplyIntent;
use App\Services\AiScheduling\SchedulingConstraint;
use App\Support\Timezone;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Queue;

/**
 * A classifier whose answer is scripted per reply body, so the state machine
 * can be driven deterministically without a network call. Falls through to
 * `unclear` for anything unscripted — the same safe default as production.
 */
class FakeClassifier implements LlmClassifier
{
    /** @var array<string, ReplyIntent> */
    public array $answers = [];

    public array $customerAnswers = [];

    public function willAnswer(string $reply, ReplyIntent $intent): void
    {
        $this->answers[$reply] = $intent;
    }

    public function willAnswerCustomer(string $reply, ReplyIntent $intent): void
    {
        $this->customerAnswers[$reply] = $intent;
    }

    public function classifyTechReply(string $reply, string $constraintDescription, string $ourLastMessage, array $priorTurns, string $today): ?ReplyIntent
    {
        return $this->answers[$reply] ?? null;
    }

    public function classifyCustomerReply(string $reply, string $constraintDescription, string $today): ?ReplyIntent
    {
        return $this->customerAnswers[$reply] ?? null;
    }

    /** @var array<string, int|null> reply body => 1-based index into the warehouse list */
    public array $warehouseAnswers = [];

    public function willPickWarehouse(string $reply, ?int $index): void
    {
        $this->warehouseAnswers[$reply] = $index;
    }

    public function classifyWarehouseReply(string $reply, array $warehouseNames): ?int
    {
        return $this->warehouseAnswers[$reply] ?? null;
    }
}

function intent(string $kind, ?string $date = null, ?string $time = null, float $confidence = 0.95): ReplyIntent
{
    return ReplyIntent::fromLlmJson([
        'intent' => $kind, 'date' => $date, 'time' => $time,
        'confidence' => $confidence, 'reason' => 'test',
    ]);
}

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    // Monday 2026-07-20 9am Pacific; the target slot is Wednesday the 22nd.
    Carbon::setTestNow(Carbon::parse('2026-07-20 09:00', 'America/Los_Angeles'));

    $this->classifier = new FakeClassifier;
    $this->app->instance(LlmClassifier::class, $this->classifier);
    $this->service = app(AiSchedulingService::class);

    $this->entityId = Entity::where('code', 'LA')->value('id');

    $this->actor = Employee::create([
        'name' => 'Dispatch', 'email' => 'd@test.local', 'entity_id' => $this->entityId,
        'job_functions' => ['office'], 'active' => true,
    ]);

    $this->jose = aiTech('Jose', $this->entityId, '+13105550001', priority: 1);
    $this->luis = aiTech('Luis', $this->entityId, '+13105550002', priority: 2);

    $this->warehouse = Warehouse::create(['name' => 'Main', 'entity_id' => $this->entityId]);

    $this->job = aiJob($this->entityId);
});

afterEach(fn () => Carbon::setTestNow());

function aiTech(string $name, int $entityId, string $phone, ?int $priority = null, bool $optedIn = true): Employee
{
    return Employee::create([
        'name' => $name, 'email' => str($name)->slug().'@test.local', 'phone' => $phone,
        'entity_id' => $entityId, 'job_functions' => ['technician'], 'active' => true,
        'priority' => $priority, 'ai_scheduling_enabled' => $optedIn,
    ]);
}

function aiJob(int $entityId): Job
{
    // A counter, not random_int: these tests also create fixture customers and
    // jobs with hand-written numbers, and a random collision with one of those
    // surfaced as a unique-constraint failure roughly once a full-suite run.
    static $n = 0;
    $n++;

    $customer = Customer::create([
        'phone_e164' => sprintf('+1818555%04d', $n), 'first_name' => 'Maria',
    ]);

    return Job::create([
        'job_number' => sprintf('LA-2026-1%04d', $n),
        'entity_id' => $entityId,
        'customer_id' => $customer->id,
        'status' => JobStatus::PendingSchedule->value,
        'vehicle_make' => 'Honda', 'vehicle_model' => 'Civic',
        'glass_position' => 'Windshield', 'city' => 'Van Nuys',
    ]);
}

/** Start a live-mode session targeting Wednesday morning. */
function startSession($test, ?SchedulingConstraint $constraint = null): AiSchedulingSession
{
    return $test->service->start(
        $test->job,
        $constraint ?? SchedulingConstraint::window('2026-07-22', 'morning'),
        $test->actor,
        techMode: AiSchedulingSession::MODE_LIVE,
        warehouseId: $test->warehouse->id,
    );
}

/**
 * Drive a tech from "yes" all the way to booked. Agreeing to a whole part of
 * day no longer books on its own — the AI comes back with the concrete 2-hour
 * slot it means to take, so a plain yes needs a second yes (or the confirm
 * window running out) before anything is written. See the slot-confirmation
 * section below for the behavior itself.
 */
function acceptAndConfirm($test, AiSchedulingSession $session, Employee $tech, string $reply = 'si'): void
{
    $test->service->simulateTechReply($session, $tech, $reply);
    $test->service->simulateTechReply($session, $tech, $reply);
}

// ---------------------------------------------------------------- outreach

it('contacts the highest-priority opted-in tech first and texts them the slot offer', function () {
    $session = startSession($this);

    expect($session->status)->toBe(AiSchedulingSession::STATUS_RUNNING)
        ->and($session->attempts)->toHaveCount(1);

    $attempt = $session->attempts->first();
    expect($attempt->employee_id)->toBe($this->jose->id)
        ->and($attempt->position)->toBe(1)
        ->and($attempt->status)->toBe(AiSchedulingAttempt::STATUS_ASKED)
        ->and($attempt->load_bucket)->toBe('open');

    // Techs default to Spanish (Employee::preferredLanguage), so the crew
    // reads the Spanish row and a Spanish date.
    $sms = ScheduledMessage::where('employee_id', $this->jose->id)->first();
    expect($sms->template_key)->toBe(TemplateKey::TechAiSlotOffer)
        ->and($sms->rendered_body)->toContain('miércoles, 22 de julio por la mañana')
        ->and($sms->rendered_body)->toContain('Honda Civic');
});

// ------------------------------------------- dispatcher-chosen contact order

it('contacts techs in the order the dispatcher dragged them into, not the roster ranking', function () {
    $session = $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE, warehouseId: $this->warehouse->id,
        techOrder: [$this->luis->id, $this->jose->id],
    );

    expect($session->attempts->first()->employee_id)->toBe($this->luis->id);

    // ...and keeps following that list as it works down it.
    $this->service->simulateTechReply($session, $this->luis, 'no puedo');

    expect($session->refresh()->currentAttempt->employee_id)->toBe($this->jose->id);
});

it('never contacts a tech left off the session contact list', function () {
    $session = $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE, warehouseId: $this->warehouse->id,
        techOrder: [$this->luis->id],
    );

    $this->service->simulateTechReply($session, $this->luis, 'no puedo');

    $session->refresh();
    expect($session->status)->toBe(AiSchedulingSession::STATUS_EXHAUSTED)
        ->and($session->attempts()->where('employee_id', $this->jose->id)->exists())->toBeFalse();
});

it('falls back to the roster ranking when no order was chosen', function () {
    $session = $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE, warehouseId: $this->warehouse->id,
        techOrder: null,
    );

    expect($session->tech_order)->toBeNull()
        ->and($session->attempts->first()->employee_id)->toBe($this->jose->id);
});

it('records the chosen contact order on the transcript', function () {
    $session = $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE, warehouseId: $this->warehouse->id,
        techOrder: [$this->luis->id, $this->jose->id],
    );

    expect($session->turns()->pluck('body')->implode("\n"))
        ->toContain('Contacting in this order: Luis, Jose');
});

it('never contacts a tech who has not opted in', function () {
    $this->jose->update(['ai_scheduling_enabled' => false]);

    $session = startSession($this);

    expect($session->attempts->first()->employee_id)->toBe($this->luis->id);
});

it('refuses to open a second live session for the same job', function () {
    startSession($this);

    expect($this->service->start(
        $this->job,
        SchedulingConstraint::window('2026-07-22', 'morning'),
        $this->actor,
    ))->toBeNull();
});

it('will not contact a tech who is mid-negotiation on another job', function () {
    startSession($this); // Jose is now live on job 1

    $otherJob = aiJob($this->entityId);
    $second = $this->service->start(
        $otherJob,
        SchedulingConstraint::window('2026-07-22', 'morning'),
        $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE,
    );

    expect($second->attempts->first()->employee_id)->toBe($this->luis->id);
});

// ------------------------------------------------------------- happy paths

it('schedules the job when the tech accepts', function () {
    $session = startSession($this);

    acceptAndConfirm($this, $session, $this->jose);

    $this->job->refresh();
    expect($this->job->status)->toBe(JobStatus::Scheduled)
        ->and($this->job->technician_id)->toBe($this->jose->id)
        ->and($this->job->warehouse_id)->toBe($this->warehouse->id)
        ->and(Timezone::toDisplay($this->job->scheduled_at)->format('Y-m-d H:i'))->toBe('2026-07-22 08:00')
        ->and($this->job->scheduled_exact_time)->toBeFalse();

    $session->refresh();
    expect($session->status)->toBe(AiSchedulingSession::STATUS_SCHEDULED)
        ->and($session->resolved_technician_id)->toBe($this->jose->id)
        ->and($session->attempts->first()->status)->toBe(AiSchedulingAttempt::STATUS_ACCEPTED);

    expect(ScheduledMessage::where('template_key', TemplateKey::TechJobConfirmation->value)->exists())->toBeTrue();
});

it('accepts a counter-offer that still fits the customers window, booking the countered time', function () {
    $session = startSession($this); // Wednesday MORNING (08:00-12:00)
    $this->classifier->willAnswer('I can be there at 10', intent('counter', '2026-07-22', '10:00'));

    $this->service->simulateTechReply($session, $this->jose, 'I can be there at 10');

    $this->job->refresh();
    expect($this->job->technician_id)->toBe($this->jose->id)
        ->and(Timezone::toDisplay($this->job->scheduled_at)->format('H:i'))->toBe('10:00');
});

it('books an exact-time request as an exact time, not a window', function () {
    $session = startSession($this, SchedulingConstraint::exact('2026-07-22', '14:00'));

    $this->service->simulateTechReply($session, $this->jose, 'ok');

    $this->job->refresh();
    expect($this->job->scheduled_exact_time)->toBeTrue()
        ->and(Timezone::toDisplay($this->job->scheduled_at)->format('H:i'))->toBe('14:00');
});

// ------------------------------------------------------ slot confirmation

/**
 * The owner's flow, verbatim (2026-07-26): a tech who agrees to a whole
 * morning gets told the concrete 2-hour slot we mean to book -- earliest
 * first -- and a short window to name a different one. A tech who already
 * named their own time skips all of this.
 */
it('proposes the earliest 2-hour slot instead of booking a whole morning outright', function () {
    $session = startSession($this); // Wednesday MORNING (08:00-12:00)

    $this->service->simulateTechReply($session, $this->jose, 'si');

    // Nothing booked yet -- we asked first.
    $this->job->refresh();
    expect($this->job->scheduled_at)->toBeNull()
        ->and($this->job->technician_id)->toBeNull();

    $attempt = $session->attempts()->first();
    expect($attempt->status)->toBe(AiSchedulingAttempt::STATUS_CONFIRMING_SLOT)
        ->and($attempt->proposed_time)->toBe('08:00')
        ->and($attempt->deadline_at->diffInMinutes(now()))->toBeLessThanOrEqual(20);

    $sms = ScheduledMessage::where('template_key', TemplateKey::TechAiSlotConfirm->value)->first();
    expect($sms->employee_id)->toBe($this->jose->id)
        ->and($sms->rendered_body)->toContain('de 8am a 10am'); // techs read Spanish
});

// ------------------------------------------------------ reaction accepts

/**
 * The owner's flow (2026-07-27): a tech who answers by TAPPING a thumbs up is
 * saying yes, but ambiguously -- so we say so out loud, name the slot, and ask
 * before anything is booked.
 */
it('reads a thumbs-up reaction back to the tech instead of booking on it', function () {
    $session = startSession($this); // Wednesday MORNING (08:00-12:00)

    $this->service->simulateTechReply(
        $session, $this->jose,
        "\u{1F44D}\u{200B} a \u{201C}\u{200A}Hola, tengo un trabajo para el miercoles\u{201D}",
    );

    $this->job->refresh();
    expect($this->job->scheduled_at)->toBeNull()
        ->and($this->job->technician_id)->toBeNull();

    $attempt = $session->attempts()->first();
    expect($attempt->status)->toBe(AiSchedulingAttempt::STATUS_CONFIRMING_SLOT)
        ->and($attempt->proposed_time)->toBe('08:00');

    // The reaction copy, not the plain slot-confirm copy.
    expect(ScheduledMessage::where('template_key', TemplateKey::TechAiSlotConfirm->value)->exists())->toBeFalse();

    $sms = ScheduledMessage::where('template_key', TemplateKey::TechAiReactionConfirm->value)->first();
    expect($sms->employee_id)->toBe($this->jose->id)
        ->and($sms->rendered_body)->toContain('de 8am a 10am');
});

it('books the slot once the tech confirms the read-back, then asks the warehouse', function () {
    $this->job->update(['warehouse_id' => null]);
    $session = $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE,
    );

    $this->service->simulateTechReply($session, $this->jose, "\u{1F44D}");
    $this->service->simulateTechReply($session, $this->jose, 'si');

    $this->job->refresh();
    expect($this->job->technician_id)->toBe($this->jose->id)
        ->and(Timezone::toDisplay($this->job->scheduled_at)->format('Y-m-d H:i'))->toBe('2026-07-22 08:00')
        ->and($session->refresh()->status)->toBe(AiSchedulingSession::STATUS_SCHEDULED);

    expect($session->attempts()->first()->status)->toBe(AiSchedulingAttempt::STATUS_AWAITING_WAREHOUSE)
        ->and(ScheduledMessage::where('template_key', TemplateKey::TechAiWarehouseAsk->value)->exists())->toBeTrue();
});

/**
 * A thumbs up on the read-back itself is the same "yes" a typed one would be
 * -- it must not loop us back into asking again.
 */
it('books when the tech thumbs-ups the read-back too', function () {
    $session = startSession($this);

    $this->service->simulateTechReply($session, $this->jose, "\u{1F44D}");
    $this->service->simulateTechReply($session, $this->jose, "\u{1F44D}\u{200B} a \u{201C}We will book it\u{201D}");

    expect($this->job->refresh()->technician_id)->toBe($this->jose->id)
        ->and($session->refresh()->status)->toBe(AiSchedulingSession::STATUS_SCHEDULED);
});

/**
 * Even an exact-time request -- where there is no slot to narrow and a typed
 * "yes" books immediately -- gets the read-back when the yes was a tap.
 */
it('confirms a reaction even when there is nothing left to narrow', function () {
    $session = startSession($this, SchedulingConstraint::exact('2026-07-22', '14:00'));

    $this->service->simulateTechReply($session, $this->jose, "\u{1F44D}");

    expect($this->job->refresh()->scheduled_at)->toBeNull();

    $sms = ScheduledMessage::where('template_key', TemplateKey::TechAiReactionConfirm->value)->first();
    expect($sms->rendered_body)->toContain('a las 2pm');
});

it('treats a thumbs-down reaction as a decline and moves on', function () {
    $session = startSession($this);

    $this->service->simulateTechReply($session, $this->jose, "\u{1F44E}\u{200B} a \u{201C}Hola\u{201D}");

    expect($session->attempts()->first()->status)->toBe(AiSchedulingAttempt::STATUS_DECLINED)
        ->and($session->refresh()->attempts()->count())->toBeGreaterThan(1);
});

it('books the proposed slot when the tech confirms it', function () {
    $session = startSession($this);

    $this->service->simulateTechReply($session, $this->jose, 'si');
    $this->service->simulateTechReply($session, $this->jose, 'ok');

    $this->job->refresh();
    expect($this->job->technician_id)->toBe($this->jose->id)
        ->and(Timezone::toDisplay($this->job->scheduled_at)->format('Y-m-d H:i'))->toBe('2026-07-22 08:00');

    expect($session->refresh()->status)->toBe(AiSchedulingSession::STATUS_SCHEDULED);
});

it('takes the techs own time when they counter the proposed slot with another morning time', function () {
    $session = startSession($this);
    $this->classifier->willAnswer('can we do 10 instead', intent('counter', '2026-07-22', '10:00'));

    $this->service->simulateTechReply($session, $this->jose, 'si');
    $this->service->simulateTechReply($session, $this->jose, 'can we do 10 instead');

    $this->job->refresh();
    expect(Timezone::toDisplay($this->job->scheduled_at)->format('H:i'))->toBe('10:00');
});

/**
 * Silence here is NOT the same as silence on the original offer: they already
 * took the job, so the confirm window running out books what we proposed
 * rather than moving on to the next tech.
 */
it('books the proposed slot anyway when the tech never answers the confirmation', function () {
    $session = startSession($this);

    $this->service->simulateTechReply($session, $this->jose, 'si');

    Carbon::setTestNow(Carbon::now()->addMinutes(21));
    $this->service->advance($session->refresh());

    $this->job->refresh();
    expect($this->job->technician_id)->toBe($this->jose->id)
        ->and(Timezone::toDisplay($this->job->scheduled_at)->format('H:i'))->toBe('08:00');

    $session->refresh();
    expect($session->status)->toBe(AiSchedulingSession::STATUS_SCHEDULED)
        ->and($session->attempts()->count())->toBe(1); // nobody else was contacted
});

it('leaves the confirmation alone until its 20 minutes are actually up', function () {
    $session = startSession($this);

    $this->service->simulateTechReply($session, $this->jose, 'si');

    Carbon::setTestNow(Carbon::now()->addMinutes(15));
    $this->service->advance($session->refresh());

    $this->job->refresh();
    expect($this->job->scheduled_at)->toBeNull()
        ->and($session->refresh()->attempts()->first()->status)
        ->toBe(AiSchedulingAttempt::STATUS_CONFIRMING_SLOT);
});

it('skips the confirmation when the tech named their own time up front', function () {
    $session = startSession($this);
    $this->classifier->willAnswer('I can do 9 to 11', intent('counter', '2026-07-22', '09:00'));

    $this->service->simulateTechReply($session, $this->jose, 'I can do 9 to 11');

    $this->job->refresh();
    expect(Timezone::toDisplay($this->job->scheduled_at)->format('H:i'))->toBe('09:00');
    expect(ScheduledMessage::where('template_key', TemplateKey::TechAiSlotConfirm->value)->exists())->toBeFalse();
});

it('skips the confirmation for an exact-time request, which has nothing left to narrow', function () {
    $session = startSession($this, SchedulingConstraint::exact('2026-07-22', '14:00'));

    $this->service->simulateTechReply($session, $this->jose, 'si');

    $this->job->refresh();
    expect(Timezone::toDisplay($this->job->scheduled_at)->format('H:i'))->toBe('14:00');
    expect(ScheduledMessage::where('template_key', TemplateKey::TechAiSlotConfirm->value)->exists())->toBeFalse();
});

it('closes on the full job card, naming the concrete slot not the customers whole window', function () {
    $session = startSession($this);

    acceptAndConfirm($this, $session, $this->jose);

    $cards = ScheduledMessage::where('template_key', TemplateKey::TechJobConfirmation->value)->get();

    // Exactly one: JobLifecycle::schedule() is told to stand down (Commit
    // passes notifyTech: false) so the session owns this send.
    expect($cards)->toHaveCount(1);

    // The booked 2-hour slot, not the customer's whole 8am-12pm morning. The
    // card phrases it from the job (the standard "entre X y Y" window wording)
    // rather than reusing the negotiation's bookedPhrase.
    expect($cards->first()->rendered_body)->toContain('entre 8am y 10am')
        ->and($cards->first()->rendered_body)->not->toContain('por la mañana')
        // The card's details, not just the slot.
        ->and($cards->first()->rendered_body)->toContain('Nombre:')
        ->and($cards->first()->rendered_body)->toContain('Telefono:')
        ->and($cards->first()->rendered_body)->toContain('Direccion:');
});

/**
 * A tech who backs out of the whole day at the confirmation stage is a
 * decline, not a slot quibble -- the pushback path still applies, so an
 * afternoon counter gets the customer's window restated.
 */
it('still pushes back when the tech counters the confirmation with a time outside the window', function () {
    $session = startSession($this);
    $this->classifier->willAnswer('actually 3pm', intent('counter', '2026-07-22', '15:00'));

    $this->service->simulateTechReply($session, $this->jose, 'si');
    $this->service->simulateTechReply($session, $this->jose, 'actually 3pm');

    $attempt = $session->attempts()->first();
    expect($attempt->status)->toBe(AiSchedulingAttempt::STATUS_NEGOTIATING)
        ->and($attempt->reiterations)->toBe(1);

    $this->job->refresh();
    expect($this->job->scheduled_at)->toBeNull();
});

// --------------------------------------------------- counters and pushback

it('pushes back on the SAME tech when a counter misses the customers window', function () {
    $session = startSession($this); // morning only
    $this->classifier->willAnswer('can I come at 3pm?', intent('counter', '2026-07-22', '15:00'));

    $this->service->simulateTechReply($session, $this->jose, 'can I come at 3pm?');

    $attempt = $session->attempts()->first();
    expect($attempt->status)->toBe(AiSchedulingAttempt::STATUS_NEGOTIATING)
        ->and($attempt->reiterations)->toBe(1)
        ->and($attempt->employee_id)->toBe($this->jose->id);

    expect(ScheduledMessage::where('employee_id', $this->jose->id)
        ->where('template_key', TemplateKey::TechAiSlotReiterate->value)->exists())->toBeTrue();

    // Still the same tech — nobody else was contacted.
    expect($session->attempts()->count())->toBe(1);
    $this->job->refresh();
    expect($this->job->technician_id)->toBeNull();
});

it('takes the tech at their word if the second counter DOES fit', function () {
    $session = startSession($this);
    $this->classifier->willAnswer('3pm?', intent('counter', '2026-07-22', '15:00'));
    $this->classifier->willAnswer('ok 9am then', intent('counter', '2026-07-22', '09:00'));

    $this->service->simulateTechReply($session, $this->jose, '3pm?');
    $this->service->simulateTechReply($session, $this->jose, 'ok 9am then');

    $this->job->refresh();
    expect($this->job->technician_id)->toBe($this->jose->id)
        ->and(Timezone::toDisplay($this->job->scheduled_at)->format('H:i'))->toBe('09:00');
});

it('moves to the next tech after a second counter that still misses the window', function () {
    $session = startSession($this);
    $this->classifier->willAnswer('3pm?', intent('counter', '2026-07-22', '15:00'));
    $this->classifier->willAnswer('only 4pm works', intent('counter', '2026-07-22', '16:00'));

    $this->service->simulateTechReply($session, $this->jose, '3pm?');
    $this->service->simulateTechReply($session, $this->jose, 'only 4pm works');

    $session->refresh();
    expect($session->attempts()->count())->toBe(2)
        ->and($session->attempts()->where('employee_id', $this->jose->id)->value('status'))
        ->toBe(AiSchedulingAttempt::STATUS_DECLINED)
        ->and($session->currentAttempt->employee_id)->toBe($this->luis->id);
});

it('rejects a counter for a different DAY even at an otherwise fine time', function () {
    $session = startSession($this);
    $this->classifier->willAnswer('thursday morning?', intent('counter', '2026-07-23', '09:00'));

    $this->service->simulateTechReply($session, $this->jose, 'thursday morning?');

    $this->job->refresh();
    expect($this->job->scheduled_at)->toBeNull();
    expect($session->attempts()->first()->reiterations)->toBe(1);
});

// -------------------------------------------------- declines and exhaustion

it('moves to the next tech on a decline', function () {
    $session = startSession($this);

    $this->service->simulateTechReply($session, $this->jose, 'no puedo');

    $session->refresh();
    expect($session->attempts()->count())->toBe(2)
        ->and($session->currentAttempt->employee_id)->toBe($this->luis->id);
});

it('exhausts the session when every tech has declined', function () {
    $session = startSession($this);

    $this->service->simulateTechReply($session, $this->jose, 'no puedo');
    $this->service->simulateTechReply($session, $this->luis, 'no puedo');

    $session->refresh();
    expect($session->status)->toBe(AiSchedulingSession::STATUS_EXHAUSTED)
        ->and($session->paused_reason)->toContain('no eligible technicians');

    $this->job->refresh();
    expect($this->job->status)->toBe(JobStatus::PendingSchedule);
});

it('times a silent tech out and moves on when advance runs after the deadline', function () {
    $session = startSession($this);

    // Past the 90-minute response window (and still inside contact hours).
    Carbon::setTestNow(Carbon::now()->addMinutes(95));
    $this->service->advance($session->refresh());

    $session->refresh();
    expect($session->attempts()->where('employee_id', $this->jose->id)->value('status'))
        ->toBe(AiSchedulingAttempt::STATUS_NO_RESPONSE)
        ->and($session->currentAttempt->employee_id)->toBe($this->luis->id);
});

it('leaves a tech alone until their deadline actually passes', function () {
    $session = startSession($this);

    // Well into the 90-minute window, but not through it.
    Carbon::setTestNow(Carbon::now()->addMinutes(75));
    $this->service->advance($session->refresh());

    expect($session->refresh()->attempts()->count())->toBe(1);
});

// ------------------------------------------------------------- escalation

it('escalates rather than guessing when the reply cannot be classified', function () {
    $session = startSession($this);

    $this->service->simulateTechReply($session, $this->jose, 'hmm depends');

    $session->refresh();
    expect($session->status)->toBe(AiSchedulingSession::STATUS_PAUSED)
        ->and($session->paused_reason)->toContain('could not read')
        ->and($session->attempts()->first()->status)->toBe(AiSchedulingAttempt::STATUS_ESCALATED);
});

it('escalates a low-confidence classification even when the intent looks decisive', function () {
    $session = startSession($this);
    $this->classifier->willAnswer('probably yeah', intent('accept', confidence: 0.4));

    $this->service->simulateTechReply($session, $this->jose, 'probably yeah');

    $session->refresh();
    expect($session->status)->toBe(AiSchedulingSession::STATUS_PAUSED);
    $this->job->refresh();
    expect($this->job->technician_id)->toBeNull();
});

it('escalates when the tech asks a question instead of answering', function () {
    $session = startSession($this);
    $this->classifier->willAnswer('which shop?', ReplyIntent::fromLlmJson([
        'intent' => 'question', 'asks' => 'which shop', 'confidence' => 0.9, 'reason' => 'asking',
    ]));

    $this->service->simulateTechReply($session, $this->jose, 'which shop?');

    $session->refresh();
    expect($session->status)->toBe(AiSchedulingSession::STATUS_PAUSED)
        ->and($session->paused_reason)->toContain('question');
});

/**
 * SFV-2026-0037 (2026-07-31): Alex answered "Si", we read the 2:30pm-4:30pm
 * slot back to him, and he replied "Ok pudes chekar la puerta en pilkinton x
 * favor" -- an accept plus an unrelated parts question. The classifier called
 * the whole thing a question, the state machine escalated, and a job the tech
 * had ALREADY TAKEN never reached the calendar. Saying nothing would have
 * booked it at the deadline, so a polite reply must not do worse.
 */
it('books the slot when the tech asks a question after already accepting', function () {
    $session = startSession($this);
    $this->classifier->willAnswer('ok can you check it at the shop?', ReplyIntent::fromLlmJson([
        'intent' => 'question', 'asks' => 'check it at the shop', 'confidence' => 0.85, 'reason' => 'asking',
    ]));

    $this->service->simulateTechReply($session, $this->jose, 'si');
    $this->service->simulateTechReply($session, $this->jose, 'ok can you check it at the shop?');

    $this->job->refresh();
    expect($this->job->technician_id)->toBe($this->jose->id)
        ->and(Timezone::toDisplay($this->job->scheduled_at)->format('Y-m-d H:i'))->toBe('2026-07-22 08:00')
        ->and($session->refresh()->status)->toBe(AiSchedulingSession::STATUS_SCHEDULED);
});

/**
 * ...and that question is usually the warehouse answer, arriving before we
 * asked for it. Take it, so we never ask what they just told us.
 */
it('takes a warehouse named in that question instead of asking for one', function () {
    Warehouse::create(['name' => 'Pilkington - Commerce', 'entity_id' => $this->entityId]);
    $this->job->update(['warehouse_id' => null]);

    $session = $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE,
    );

    $this->classifier->willAnswer('ok can you check the door at Pilkington?', ReplyIntent::fromLlmJson([
        'intent' => 'question', 'asks' => 'check the door at Pilkington',
        'confidence' => 0.85, 'reason' => 'asking',
    ]));

    $this->service->simulateTechReply($session, $this->jose, 'si');
    $this->service->simulateTechReply($session, $this->jose, 'ok can you check the door at Pilkington?');

    $this->job->refresh();
    expect($this->job->technician_id)->toBe($this->jose->id)
        ->and($this->job->warehouse?->name)->toBe('Pilkington - Commerce')
        // Nothing left to ask -- they already answered it.
        ->and(ScheduledMessage::where('template_key', TemplateKey::TechAiWarehouseAsk->value)->exists())
        ->toBeFalse();

    // So the job card names the real supplier, not "waiting on you to select warehouse".
    $card = ScheduledMessage::where('template_key', TemplateKey::TechJobConfirmation->value)->first();
    expect($card->rendered_body)->toContain('Pilkington - Commerce');
});

// ------------------------------------------------------------ manual moves

it('skips the current tech on request and asks the next one', function () {
    $session = startSession($this);

    $this->service->skipTech($session, 'called out sick');

    $session->refresh();
    expect($session->attempts()->where('employee_id', $this->jose->id)->value('status'))
        ->toBe(AiSchedulingAttempt::STATUS_SKIPPED)
        ->and($session->currentAttempt->employee_id)->toBe($this->luis->id);
});

it('pauses and resumes without losing the current tech', function () {
    $session = startSession($this);

    $this->service->pause($session, 'dispatcher took over');
    expect($session->refresh()->status)->toBe(AiSchedulingSession::STATUS_PAUSED);

    // A reply while paused is ignored rather than acted on.
    $this->service->simulateTechReply($session, $this->jose, 'si');
    $this->job->refresh();
    expect($this->job->technician_id)->toBeNull();

    $this->service->resume($session->refresh());
    expect($session->refresh()->status)->toBe(AiSchedulingSession::STATUS_RUNNING);
});

it('cancels a session and supersedes the tech in flight', function () {
    $session = startSession($this);

    $this->service->cancel($session);

    $session->refresh();
    expect($session->status)->toBe(AiSchedulingSession::STATUS_CANCELED)
        ->and($session->attempts()->first()->status)->toBe(AiSchedulingAttempt::STATUS_SUPERSEDED);
});

// ----------------------------------------------------------- simulate mode

it('sends absolutely nothing in simulate mode but records the full transcript', function () {
    $session = $this->service->start(
        $this->job,
        SchedulingConstraint::window('2026-07-22', 'morning'),
        $this->actor,
        techMode: AiSchedulingSession::MODE_SIMULATE,
        warehouseId: $this->warehouse->id,
    );

    acceptAndConfirm($this, $session, $this->jose);

    expect(ScheduledMessage::count())->toBe(0);

    // The negotiation really ran and resolved...
    $session->refresh();
    expect($session->status)->toBe(AiSchedulingSession::STATUS_SIMULATED)
        ->and($session->resolved_technician_id)->toBe($this->jose->id)
        ->and($session->attempts()->first()->status)->toBe(AiSchedulingAttempt::STATUS_ACCEPTED);

    $outbound = $session->turns()->where('direction', AiSchedulingTurn::DIRECTION_OUT)->get();
    expect($outbound)->not->toBeEmpty()
        ->and($outbound->first()->body)->toContain('miércoles, 22 de julio');
});

/**
 * The whole point of simulate mode. Committing calls JobLifecycle::schedule,
 * which texts the CUSTOMER their time confirmation and builds their
 * reminders — a simulation that did that would be worse than useless.
 */
it('never touches the real job or texts the customer in simulate mode', function () {
    $session = $this->service->start(
        $this->job,
        SchedulingConstraint::window('2026-07-22', 'morning'),
        $this->actor,
        techMode: AiSchedulingSession::MODE_SIMULATE,
        warehouseId: $this->warehouse->id,
    );

    acceptAndConfirm($this, $session, $this->jose);

    $this->job->refresh();
    expect($this->job->technician_id)->toBeNull()
        ->and($this->job->scheduled_at)->toBeNull()
        ->and($this->job->status)->toBe(JobStatus::PendingSchedule);

    expect(ScheduledMessage::whereNotNull('customer_id')->count())->toBe(0);

    // ...but it says exactly what it would have done.
    $note = $session->turns()->where('direction', AiSchedulingTurn::DIRECTION_NOTE)->get()
        ->firstWhere(fn ($t) => str_contains((string) $t->body, 'SIMULATION'));
    expect($note->body)->toContain('Would have assigned them')
        ->and($note->body)->toContain('08:00');
});

it('parks outbound texts for approval in approve mode', function () {
    $session = $this->service->start(
        $this->job,
        SchedulingConstraint::window('2026-07-22', 'morning'),
        $this->actor,
        techMode: AiSchedulingSession::MODE_APPROVE,
        warehouseId: $this->warehouse->id,
    );

    expect(ScheduledMessage::count())->toBe(0);

    $pending = $session->turns()->where('approval_status', AiSchedulingTurn::APPROVAL_PENDING)->first();
    expect($pending)->not->toBeNull()
        ->and($pending->pending_payload['template'])->toBe(TemplateKey::TechAiSlotOffer->value);

    $this->service->approveOutbound($pending);

    expect(ScheduledMessage::where('employee_id', $this->jose->id)->count())->toBe(1)
        ->and($pending->refresh()->approval_status)->toBe(AiSchedulingTurn::APPROVAL_APPROVED);
});

// ------------------------------------------------------------- transcript

it('records every turn with its classification and whether the LLM was used', function () {
    $session = startSession($this);
    $this->classifier->willAnswer('I can be there at 10', intent('counter', '2026-07-22', '10:00'));

    $this->service->simulateTechReply($session, $this->jose, 'I can be there at 10');

    $inbound = $session->turns()->where('direction', AiSchedulingTurn::DIRECTION_IN)->first();
    expect($inbound->intent)->toBe(ReplyIntent::COUNTER)
        ->and($inbound->used_llm)->toBeTrue()
        ->and($inbound->classification['time'])->toBe('10:00')
        ->and((float) $inbound->confidence)->toBe(0.95);
});

it('marks a fast-path reply as not having used the LLM', function () {
    $session = startSession($this);

    $this->service->simulateTechReply($session, $this->jose, 'si');

    $inbound = $session->turns()->where('direction', AiSchedulingTurn::DIRECTION_IN)->first();
    expect($inbound->used_llm)->toBeFalse()
        ->and($inbound->intent)->toBe(ReplyIntent::ACCEPT);
});
