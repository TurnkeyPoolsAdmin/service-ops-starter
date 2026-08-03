<?php

use App\Enums\PaymentMethod;
use App\Enums\TemplateKey;
use App\Filament\Pages\OpenTasks;
use App\Filament\Pages\ReviewCallScoreboard;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\MessageTemplate;
use App\Models\ReviewRequest;
use App\Models\Setting;
use App\Services\Calibration\CalibrationService;
use App\Services\JobLifecycle;
use App\Services\Reviews\ReviewDripService;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    Entity::query()->update(['google_review_url' => 'https://g.page/r/test-review']);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op-cf@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->tech = Employee::create([
        'name' => 'Tech', 'email' => 'tech-cf@test.local', 'active' => true,
        'job_functions' => ['technician'], 'phone' => '+18185550001',
    ]);

    $this->lifecycle = app(JobLifecycle::class);
    $this->drips = app(ReviewDripService::class);
    $this->calibration = app(CalibrationService::class);

    $this->actingAs($this->actor);
});

afterEach(fn () => Carbon::setTestNow());

function cfJob($test, array $overrides = []): Job
{
    $job = $test->lifecycle->createFromIntake(array_merge([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Robin',
        'quote_amount_cents' => 45000,
    ], $overrides), $test->actor);
    $test->lifecycle->assignTechnician($job, $test->tech->id, $test->actor);

    return $job->refresh();
}

/** Schedules, pays in full, and marks work complete — opening awaiting_call. */
function cfComplete($test, Job $job): Job
{
    $test->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $test->actor);
    $test->lifecycle->markWorkComplete($job->refresh(), $test->actor);

    return $job->refresh();
}

// ---- Calibration deferral ----

it('does not open a review request when a calibration job completes; opens it once ops confirms', function () {
    $job = cfJob($this, [
        'needs_calibration' => true,
        'vehicle_year' => '2020', 'vehicle_make' => 'Toyota', 'vehicle_model' => 'Camry',
    ]);
    $this->lifecycle->markWorkComplete($job->refresh(), $this->actor);

    expect($job->refresh()->reviewDeferredForCalibration())->toBeTrue()
        ->and(ReviewRequest::where('job_id', $job->id)->exists())->toBeFalse();

    $this->calibration->markComplete(
        $job->refresh(),
        'calibration/'.$job->id.'/invoice.pdf',
        'calibration/'.$job->id.'/report.pdf',
        $this->actor,
    );
    $this->calibration->confirm($job->refresh(), wentWell: true, actor: $this->actor);

    $request = ReviewRequest::where('job_id', $job->id)->first();
    expect($request)->not->toBeNull()
        ->and($request->status)->toBe(ReviewRequest::STATUS_AWAITING_CALL);
});

it('opens the review request even when calibration itself did not go well', function () {
    $job = cfJob($this, [
        'needs_calibration' => true,
        'vehicle_year' => '2020', 'vehicle_make' => 'Toyota', 'vehicle_model' => 'Camry',
    ]);
    $this->lifecycle->markWorkComplete($job->refresh(), $this->actor);

    $this->calibration->markComplete(
        $job->refresh(),
        'calibration/'.$job->id.'/invoice.pdf',
        'calibration/'.$job->id.'/report.pdf',
        $this->actor,
    );
    $this->calibration->confirm($job->refresh(), wentWell: false, actor: $this->actor);

    $request = ReviewRequest::where('job_id', $job->id)->first();
    expect($request)->not->toBeNull()
        ->and($request->status)->toBe(ReviewRequest::STATUS_AWAITING_CALL)
        ->and($job->refresh()->calibration_went_well)->toBeFalse();
});

// ---- 7pm deadline auto-fallthrough ----

it('auto-falls-through an un-logged first call past its 7pm deadline', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00', 'America/Los_Angeles'));

    $job = cfJob($this);
    cfComplete($this, $job);
    $request = ReviewRequest::where('job_id', $job->id)->first();

    // Not yet 7pm — the sweep leaves it alone.
    $count = $this->drips->autoStartOverdueFirstCalls();
    expect($count)->toBe(0)
        ->and($request->refresh()->status)->toBe(ReviewRequest::STATUS_AWAITING_CALL);

    Carbon::setTestNow(Carbon::parse('2026-07-20 19:01:00', 'America/Los_Angeles'));

    $count = $this->drips->autoStartOverdueFirstCalls();
    $request->refresh();
    expect($count)->toBe(1)
        ->and($request->status)->toBe(ReviewRequest::STATUS_ACTIVE)
        ->and($request->sentiment)->toBe('up')
        ->and($request->first_call_outcome)->toBe(ReviewRequest::FIRST_CALL_AUTO)
        ->and($request->step1_template)->toBe(TemplateKey::ReviewRequest1->value);

    expect($job->scheduledMessages()->where('template_key', TemplateKey::ReviewRequest1->value)->exists())->toBeTrue();
});

it('auto-falls-through a "skip for later" first call past the deadline too', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-20 10:00:00', 'America/Los_Angeles'));

    $job = cfJob($this);
    cfComplete($this, $job);
    $request = ReviewRequest::where('job_id', $job->id)->first();
    $this->drips->logFirstCall($request, 'skip', null, $this->actor);

    Carbon::setTestNow(Carbon::parse('2026-07-20 19:30:00', 'America/Los_Angeles'));

    $this->drips->autoStartOverdueFirstCalls();

    expect($request->refresh()->status)->toBe(ReviewRequest::STATUS_ACTIVE)
        ->and($request->first_call_outcome)->toBe(ReviewRequest::FIRST_CALL_AUTO);
});

it('a row created after the 7pm deadline falls through on the very next sweep', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-20 20:15:00', 'America/Los_Angeles'));

    $job = cfJob($this);
    cfComplete($this, $job);
    $request = ReviewRequest::where('job_id', $job->id)->first();

    expect($request->isFirstCallOverdue())->toBeTrue();

    $count = $this->drips->autoStartOverdueFirstCalls();
    expect($count)->toBe(1)
        ->and($request->refresh()->status)->toBe(ReviewRequest::STATUS_ACTIVE);
});

it('reviews:advance falls through overdue first calls before advancing the rest', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-20 20:00:00', 'America/Los_Angeles'));

    $job = cfJob($this);
    cfComplete($this, $job);
    $request = ReviewRequest::where('job_id', $job->id)->first();

    $this->artisan('reviews:advance')->assertSuccessful();

    expect($request->refresh()->status)->toBe(ReviewRequest::STATUS_ACTIVE)
        ->and($request->step1_sent_at)->not->toBeNull();
});

// ---- Blocked send keeps the connected template on retry ----

it('a blocked connected first-call send retries with review_connected, not review_request_1', function () {
    Entity::query()->update(['google_review_url' => null]);

    $job = cfJob($this);
    cfComplete($this, $job);
    $request = ReviewRequest::where('job_id', $job->id)->first();

    $this->drips->logFirstCall($request, ReviewRequest::FIRST_CALL_CONNECTED, true, $this->actor);

    $request->refresh();
    expect($request->step1_sent_at)->toBeNull()
        ->and($request->first_call_outcome)->toBe(ReviewRequest::FIRST_CALL_CONNECTED);

    Entity::query()->update(['google_review_url' => 'https://g.page/r/test-review']);
    $this->artisan('reviews:advance')->assertSuccessful();

    $request->refresh();
    expect($request->step1_sent_at)->not->toBeNull()
        ->and($request->step1_template)->toBe(TemplateKey::ReviewConnected->value)
        ->and($job->scheduledMessages()->where('template_key', TemplateKey::ReviewConnected->value)->exists())->toBeTrue()
        ->and($job->scheduledMessages()->where('template_key', TemplateKey::ReviewRequest1->value)->exists())->toBeFalse();
});

// ---- Telegram digest ----

it('the review-call Telegram digest lists a job skipped for later and clears once logged', function () {
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00', 'America/Los_Angeles'));

    $job = cfJob($this);
    cfComplete($this, $job);
    $request = ReviewRequest::where('job_id', $job->id)->first();
    $this->drips->logFirstCall($request, 'skip', null, $this->actor);

    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    $this->artisan('alerts:telegram-review-calls')->assertSuccessful();

    Http::assertSent(fn ($req) => str_contains($req->url(), '/bottest-token/sendMessage')
        && str_contains($req['text'], 'Review call needed')
        && str_contains($req['text'], $job->job_number));

    $this->drips->logFirstCall($request->refresh(), ReviewRequest::FIRST_CALL_NO_ANSWER, true, $this->actor);

    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 2]], 200)]);
    $this->artisan('alerts:telegram-review-calls')->assertSuccessful();

    Http::assertNothingSent();
});

it('the review-call Telegram digest stays quiet outside its configured window', function () {
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Carbon::setTestNow(Carbon::parse('2026-07-20 23:00:00', 'America/Los_Angeles'));

    $job = cfJob($this);
    cfComplete($this, $job);

    Http::fake();
    $this->artisan('alerts:telegram-review-calls')->assertSuccessful();

    Http::assertNothingSent();
});

// ---- Open Tasks ----

it('Open Tasks lists a job under Review call needed and clears once the first call is logged', function () {
    $job = cfJob($this);
    cfComplete($this, $job);

    expect($job->refresh()->openTaskTypes())->toContain('review_call');

    Livewire::actingAs($this->actor)
        ->withQueryParams(['view_tab' => 'review_call'])
        ->test(OpenTasks::class)
        ->assertSee($job->job_number);

    $request = ReviewRequest::where('job_id', $job->id)->first();
    $this->drips->logFirstCall($request, ReviewRequest::FIRST_CALL_NO_ANSWER, true, $this->actor);

    expect($job->refresh()->openTaskTypes())->not->toContain('review_call');
});

// ---- Scoreboard ----

it('the Review Call Scoreboard counts first calls and second calls, and a job can contribute both', function () {
    $job = cfJob($this);
    cfComplete($this, $job);
    $request = ReviewRequest::where('job_id', $job->id)->first();

    $this->drips->logFirstCall($request, ReviewRequest::FIRST_CALL_NO_ANSWER, true, $this->actor);
    $request->refresh()->update(['step2_sent_at' => now(), 'next_send_at' => null]);
    $this->drips->logCall($request->refresh(), ReviewRequest::OUTCOME_VOICEMAIL, null, $this->actor);

    $page = Livewire::test(ReviewCallScoreboard::class, ['range' => 'today'])->assertOk();
    $row = $page->instance()->getTable()->getRecords()->first();

    expect((int) $row->window_first_calls_count)->toBe(1)
        ->and((int) $row->window_calls_count)->toBe(1);
});

// ---- Template sanity ----

it('review_connected requires the google review url and carries opt-out language', function () {
    expect(TemplateKey::ReviewConnected->requiredVars())->toContain('google_review_url')
        ->and(TemplateKey::ReviewConnected->requiresOptOutLanguage())->toBeTrue();

    foreach (MessageTemplate::where('key', TemplateKey::ReviewConnected->value)->get() as $row) {
        expect(stripos($row->body, 'STOP'))->not->toBeFalse();
    }
});
