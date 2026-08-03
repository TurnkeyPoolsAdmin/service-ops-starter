<?php

use App\Enums\PartsSupplier;
use App\Enums\TemplateKey;
use App\Models\AiSchedulingAttempt;
use App\Models\AiSchedulingSession;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\ScheduledMessage;
use App\Models\Warehouse;
use App\Services\AiScheduling\AiSchedulingService;
use App\Services\AiScheduling\LlmClassifier;
use App\Services\AiScheduling\SchedulingConstraint;
use App\Services\AiScheduling\WarehouseResolver;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Queue;

/**
 * "After it is booked, ask the tech which warehouse to order the part from"
 * (owner, 2026-07-26). The job is already on the calendar by the time this
 * runs, so nothing here may block, pause or overwrite anything — an answer we
 * can't read simply leaves the parts source for the dispatcher who orders it.
 *
 * Shares the fixture helpers (aiTech/aiJob/FakeClassifier) defined in
 * AiSchedulingFlowTest, like the other files in this family.
 */
beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    Carbon::setTestNow(Carbon::parse('2026-07-20 09:00', 'America/Los_Angeles'));

    $this->classifier = new FakeClassifier;
    $this->app->instance(LlmClassifier::class, $this->classifier);
    $this->service = app(AiSchedulingService::class);

    $this->entityId = Entity::where('code', 'LA')->value('id');
    $this->actor = Employee::create([
        'name' => 'Dispatch', 'email' => 'dw@test.local', 'entity_id' => $this->entityId,
        'job_functions' => ['office'], 'active' => true,
    ]);
    $this->jose = aiTech('Jose', $this->entityId, '+13105550001', priority: 1);
    $this->job = aiJob($this->entityId);

    // The real prod list, near enough: several branches per supplier, which is
    // what makes a bare "mygrant" genuinely ambiguous.
    $this->compton = Warehouse::create(['name' => 'Mygrant Glass - Compton', 'supplier' => PartsSupplier::Mygrant]);
    $this->sanFernando = Warehouse::create(['name' => 'Mygrant Glass - San Fernando', 'supplier' => PartsSupplier::Mygrant]);
    $this->igcLa = Warehouse::create(['name' => 'Import Glass Corporation - Los Angeles', 'supplier' => PartsSupplier::Igc]);
    $this->pilkington = Warehouse::create(['name' => 'Pilkington - Commerce']);
});

afterEach(fn () => Carbon::setTestNow());

/** Book a job with NO warehouse chosen up front, so the AI has to ask. */
function bookWithoutWarehouse($test): AiSchedulingSession
{
    $session = $test->service->start(
        $test->job, SchedulingConstraint::exact('2026-07-22', '09:00'), $test->actor,
        techMode: AiSchedulingSession::MODE_LIVE,
    );

    $test->service->simulateTechReply($session, $test->jose, 'si');

    return $session->refresh();
}

// ------------------------------------------------------------------ the ask

it('asks the tech which warehouse right after the job is booked', function () {
    $session = bookWithoutWarehouse($this);

    // The booking itself went through untouched.
    $this->job->refresh();
    expect($this->job->technician_id)->toBe($this->jose->id)
        ->and($this->job->scheduled_at)->not->toBeNull()
        ->and($session->status)->toBe(AiSchedulingSession::STATUS_SCHEDULED);

    $attempt = $session->attempts()->first();
    expect($attempt->status)->toBe(AiSchedulingAttempt::STATUS_AWAITING_WAREHOUSE)
        ->and($attempt->deadline_at->diffInMinutes(now()))->toBeLessThanOrEqual(30);

    expect(ScheduledMessage::where('template_key', TemplateKey::TechAiWarehouseAsk->value)
        ->where('employee_id', $this->jose->id)->exists())->toBeTrue();
});

it('does not ask when the warehouse was already chosen in the launch modal', function () {
    $session = $this->service->start(
        $this->job, SchedulingConstraint::exact('2026-07-22', '09:00'), $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE, warehouseId: $this->compton->id,
    );

    $this->service->simulateTechReply($session, $this->jose, 'si');

    expect($session->refresh()->attempts()->first()->status)->toBe(AiSchedulingAttempt::STATUS_ACCEPTED)
        ->and(ScheduledMessage::where('template_key', TemplateKey::TechAiWarehouseAsk->value)->exists())->toBeFalse();
});

it('does not ask on a labor-only job - the customer brings the glass', function () {
    $this->job->update(['labor_only' => true]);

    $session = bookWithoutWarehouse($this);

    expect($session->attempts()->first()->status)->toBe(AiSchedulingAttempt::STATUS_ACCEPTED)
        ->and(ScheduledMessage::where('template_key', TemplateKey::TechAiWarehouseAsk->value)->exists())->toBeFalse();
});

// --------------------------------------------------------------- the answer

it('sets the parts source from the techs answer and confirms it back', function () {
    $session = bookWithoutWarehouse($this);

    $this->service->simulateTechReply($session, $this->jose, 'mygrant san fernando');

    $this->job->refresh();
    expect($this->job->warehouse_id)->toBe($this->sanFernando->id)
        ->and($this->job->warehouse_other)->toBeNull();

    $confirm = ScheduledMessage::where('template_key', TemplateKey::TechAiWarehouseSet->value)->first();
    expect($confirm->rendered_body)->toContain('Mygrant Glass - San Fernando');

    expect($session->refresh()->attempts()->first()->status)->toBe(AiSchedulingAttempt::STATUS_ACCEPTED);
});

it('leaves the parts source for dispatch when the answer names no warehouse we have', function () {
    $session = bookWithoutWarehouse($this);

    $this->service->simulateTechReply($session, $this->jose, 'the place on Sherman Way');

    $this->job->refresh();
    expect($this->job->warehouse_id)->toBeNull()
        ->and($this->job->warehouse_other)->toBeNull();

    $session->refresh();
    expect($session->attempts()->first()->status)->toBe(AiSchedulingAttempt::STATUS_ACCEPTED);

    $notes = $session->turns()->where('direction', 'note')->pluck('body')->implode(' ');
    expect($notes)->toContain('Could not tell which warehouse');

    // Never guesses, and never nags.
    expect(ScheduledMessage::where('template_key', TemplateKey::TechAiWarehouseSet->value)->exists())->toBeFalse();
});

it('falls back to the model when the words alone say nothing, and takes its pick from the real list', function () {
    $session = bookWithoutWarehouse($this);

    // Nothing in "the usual spot" names a warehouse, so the free pass can't
    // score it. The model answers with an INDEX into the list we sent it,
    // alphabetical: 1 Import Glass, 2 Mygrant Compton, 3 Mygrant San Fernando,
    // 4 Pilkington.
    $this->classifier->willPickWarehouse('the usual spot', 2);

    $this->service->simulateTechReply($session, $this->jose, 'the usual spot');

    expect($this->job->refresh()->warehouse_id)->toBe($this->compton->id);

    $turn = $session->refresh()->turns()->where('intent', 'warehouse')->first();
    expect($turn->used_llm)->toBeTrue()
        ->and($turn->classification['warehouse'])->toBe('Mygrant Glass - Compton');
});

it('costs no API call when the tech just names the warehouse', function () {
    $session = bookWithoutWarehouse($this);

    $this->service->simulateTechReply($session, $this->jose, 'Pilkington');

    $turn = $session->refresh()->turns()->where('intent', 'warehouse')->first();
    expect($turn->used_llm)->toBeFalse()
        ->and($this->job->refresh()->warehouse_id)->toBe($this->pilkington->id);
});

it('ignores an out-of-range index from the model rather than picking something', function () {
    $session = bookWithoutWarehouse($this);
    $this->classifier->willPickWarehouse('somewhere else', 99);

    $this->service->simulateTechReply($session, $this->jose, 'somewhere else');

    expect($this->job->refresh()->warehouse_id)->toBeNull();
});

/**
 * The dispatcher's pick outranks the tech's text — they may well have ordered
 * the part in the minutes we were waiting.
 */
it('does not overwrite a warehouse a human set while we were waiting', function () {
    $session = bookWithoutWarehouse($this);
    $this->job->update(['warehouse_id' => $this->pilkington->id]);

    $this->service->simulateTechReply($session, $this->jose, 'mygrant san fernando');

    expect($this->job->refresh()->warehouse_id)->toBe($this->pilkington->id);

    $notes = $session->refresh()->turns()->where('direction', 'note')->pluck('body')->implode(' ');
    expect($notes)->toContain('already had a parts source');
});

// ------------------------------------------------------------------- expiry

it('closes an unanswered warehouse ask on the sweep, without touching the booking', function () {
    $session = bookWithoutWarehouse($this);

    Carbon::setTestNow(Carbon::now()->addMinutes(31));
    $result = $this->service->sweep();

    expect($result['warehouse_asks_closed'])->toBe(1)
        ->and($session->refresh()->attempts()->first()->status)->toBe(AiSchedulingAttempt::STATUS_ACCEPTED);

    $this->job->refresh();
    expect($this->job->technician_id)->toBe($this->jose->id)
        ->and($this->job->warehouse_id)->toBeNull();
});

/**
 * The ask is a LIVE attempt status, which is what routes the answer back to
 * this job — and it must therefore also keep the tech off another session's
 * offer list until it closes.
 */
it('keeps the tech out of other sessions while their warehouse answer is outstanding', function () {
    bookWithoutWarehouse($this);

    $luis = aiTech('Luis', $this->entityId, '+13105550002', priority: 2);
    $other = $this->service->start(
        aiJob($this->entityId), SchedulingConstraint::exact('2026-07-23', '09:00'), $this->actor,
        techMode: AiSchedulingSession::MODE_LIVE,
    );

    expect($other->attempts->first()->employee_id)->toBe($luis->id);
});

// ------------------------------------------------- the deterministic matcher

it('matches a warehouse on its distinctive words, ignoring generic ones', function () {
    $options = app(WarehouseResolver::class)->options();

    expect(WarehouseResolver::match('Mygrant San Fernando', $options)?->id)->toBe($this->sanFernando->id)
        ->and(WarehouseResolver::match('pilkington', $options)?->id)->toBe($this->pilkington->id)
        ->and(WarehouseResolver::match('import glass los angeles', $options)?->id)->toBe($this->igcLa->id)
        // The supplier nickname the crew actually uses, which is nowhere in the name.
        ->and(WarehouseResolver::match('igc', $options)?->id)->toBe($this->igcLa->id);
});

it('refuses to pick between two branches of the same supplier', function () {
    $options = app(WarehouseResolver::class)->options();

    expect(WarehouseResolver::match('mygrant', $options))->toBeNull()
        ->and(WarehouseResolver::match('whatever you think', $options))->toBeNull()
        ->and(WarehouseResolver::match('', $options))->toBeNull();
});

it('does not let a generic word inside another name score a match', function () {
    $options = app(WarehouseResolver::class)->options();

    // "glass" is in three of the four names; on its own it means nothing.
    expect(WarehouseResolver::match('glass', $options))->toBeNull();
});
