<?php

use App\Filament\Resources\Jobs\JobResource;
use App\Filament\Resources\Jobs\Support\JobTimeline;
use App\Models\AiSchedulingSession;
use App\Models\Employee;
use App\Models\Entity;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    Carbon::setTestNow(Carbon::parse('2026-07-20 09:00', 'America/Los_Angeles'));

    $this->app->instance(LlmClassifier::class, new FakeClassifier);
    $this->classifier = $this->app->make(LlmClassifier::class);
    $this->service = app(AiSchedulingService::class);

    $this->entityId = Entity::where('code', 'LA')->value('id');
    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'jv-admin@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['manager'], 'entity_id' => $this->entityId,
    ]);
    $this->admin->assignRole('super_admin');
    $this->actingAs($this->admin);

    $this->jose = aiTech('Jose', $this->entityId, '+13105550001', priority: 1);
    $this->warehouse = Warehouse::create(['name' => 'Main', 'entity_id' => $this->entityId]);
    $this->job = aiJob($this->entityId);
});

afterEach(fn () => Carbon::setTestNow());

// ------------------------------------------------------------------ badge

it('shows no AI scheduling badge on the job view when there is no live session', function () {
    $this->get(JobResource::getUrl('view', ['record' => $this->job]))
        ->assertSuccessful()
        ->assertDontSee('Session running')
        ->assertDontSee('Session paused');
});

it('shows the AI scheduling badge while a session is running', function () {
    $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->admin,
        techMode: AiSchedulingSession::MODE_SIMULATE, warehouseId: $this->warehouse->id,
    );

    $this->get(JobResource::getUrl('view', ['record' => $this->job]))
        ->assertSuccessful()
        ->assertSee('Session running');
});

it('shows the badge as paused once the session is paused', function () {
    $session = $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->admin,
        techMode: AiSchedulingSession::MODE_SIMULATE, warehouseId: $this->warehouse->id,
    );
    $this->service->pause($session, 'checking with dispatch');

    $this->get(JobResource::getUrl('view', ['record' => $this->job]))
        ->assertSuccessful()
        ->assertSee('Session paused');
});

it('hides the badge once the session has resolved (simulated)', function () {
    $session = $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->admin,
        techMode: AiSchedulingSession::MODE_SIMULATE, warehouseId: $this->warehouse->id,
    );
    $this->service->simulateTechReply($session, $this->jose, 'si');
    $this->service->simulateTechReply($session, $this->jose, 'si');

    $this->get(JobResource::getUrl('view', ['record' => $this->job]))
        ->assertSuccessful()
        ->assertDontSee('Session running')
        ->assertDontSee('Session paused');
});

it('exposes liveAiSchedulingSession() on the Job model', function () {
    expect($this->job->liveAiSchedulingSession())->toBeNull();

    $session = $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->admin,
        techMode: AiSchedulingSession::MODE_SIMULATE, warehouseId: $this->warehouse->id,
    );

    expect($this->job->liveAiSchedulingSession()?->id)->toBe($session->id);
});

// --------------------------------------------------------------- timeline

it('interleaves the AI scheduling conversation into the job timeline', function () {
    $session = $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->admin,
        techMode: AiSchedulingSession::MODE_LIVE, warehouseId: $this->warehouse->id,
    );
    $this->service->simulateTechReply($session, $this->jose, 'no puedo');

    $html = (string) JobTimeline::html($this->job->refresh());

    expect($html)->toContain('🤖')
        ->toContain('AI texted Jose')
        ->toContain('AI heard back from Jose')
        ->toContain('read as decline');
});

it('does not leak internal system notes into the job timeline', function () {
    $session = $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->admin,
        techMode: AiSchedulingSession::MODE_SIMULATE, warehouseId: $this->warehouse->id,
    );

    $html = (string) JobTimeline::html($this->job->refresh());

    // A system note is on the transcript (AiSchedulingTranscript), not here.
    expect($html)->not->toContain('Session started by');
});

// --------------------------------------------------------------- telegram

function enableTelegram(): void
{
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
}

/** Run a session all the way to a real booking: live mode, tech says yes. */
function bookViaAi($test): AiSchedulingSession
{
    $session = $test->service->start(
        $test->job, SchedulingConstraint::window('2026-07-22', 'morning'), $test->admin,
        techMode: AiSchedulingSession::MODE_LIVE, warehouseId: $test->warehouse->id,
    );

    // Twice: yes to the morning, then yes to the 8am-10am slot it proposes.
    $test->service->simulateTechReply($session, $test->jose, 'si');
    $test->service->simulateTechReply($session, $test->jose, 'si');

    return $session->refresh();
}

it('posts to Telegram when the AI actually books a job, so it can be spot-checked', function () {
    enableTelegram();

    $session = bookViaAi($this);
    expect($session->status)->toBe(AiSchedulingSession::STATUS_SCHEDULED);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
        && str_contains($request['text'], 'AI scheduling booked a job')
        && str_contains($request['text'], $this->job->job_number)
        && str_contains($request['text'], 'Jose'));
});

// The booking alert is the ONLY one. Everything below used to post to the group
// chat and deliberately no longer does (owner, 2026-07-26) — it's all visible on
// the AI Scheduling page.

it('stays silent when a session escalates for a human', function () {
    enableTelegram();
    $session = $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->admin,
        techMode: AiSchedulingSession::MODE_SIMULATE, warehouseId: $this->warehouse->id,
    );

    $this->service->simulateTechReply($session, $this->jose, 'hmm not sure');

    expect($session->refresh()->status)->toBe(AiSchedulingSession::STATUS_PAUSED);
    Http::assertNothingSent();
});

it('stays silent when a session exhausts every technician', function () {
    enableTelegram();
    $session = $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->admin,
        techMode: AiSchedulingSession::MODE_SIMULATE, warehouseId: $this->warehouse->id,
    );

    $this->service->simulateTechReply($session, $this->jose, 'no puedo');

    expect($session->refresh()->status)->toBe(AiSchedulingSession::STATUS_EXHAUSTED);
    Http::assertNothingSent();
});

it('stays silent when the customer retargets the session', function () {
    enableTelegram();
    $session = $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->admin,
        techMode: AiSchedulingSession::MODE_LIVE, warehouseId: $this->warehouse->id,
    );
    $this->classifier->willAnswerCustomer('thursday instead', ReplyIntent::fromLlmJson([
        'intent' => 'counter', 'date' => '2026-07-23', 'confidence' => 0.9, 'reason' => 'x',
    ]));

    $this->service->simulateCustomerReply($session, $this->job->customer, 'thursday instead');

    expect($session->refresh()->retarget_count)->toBe(1);
    Http::assertNothingSent();
});

it('stays silent when a session expires past its requested day', function () {
    enableTelegram();
    $session = $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->admin,
        techMode: AiSchedulingSession::MODE_SIMULATE, warehouseId: $this->warehouse->id,
    );

    Carbon::setTestNow(Carbon::parse('2026-07-23 09:00', 'America/Los_Angeles'));
    $this->service->sweep();

    Http::assertNothingSent();
    expect($session->refresh()->status)->toBe(AiSchedulingSession::STATUS_CANCELED);
});

it('does not alert on a simulated booking - nothing real was scheduled', function () {
    enableTelegram();
    $session = $this->service->start(
        $this->job, SchedulingConstraint::window('2026-07-22', 'morning'), $this->admin,
        techMode: AiSchedulingSession::MODE_SIMULATE, warehouseId: $this->warehouse->id,
    );

    $this->service->simulateTechReply($session, $this->jose, 'si');
    $this->service->simulateTechReply($session, $this->jose, 'si');

    expect($session->refresh()->status)->toBe(AiSchedulingSession::STATUS_SIMULATED);
    Http::assertNothingSent();
});

it('stays silent when Telegram is not configured', function () {
    Http::fake();

    bookViaAi($this);

    Http::assertNothingSent();
});

it('stays silent when telegram_alerts_enabled is off, even if configured', function () {
    enableTelegram();
    Setting::put('telegram_alerts_enabled', '0', sensitive: false);

    bookViaAi($this);

    Http::assertNothingSent();
});
