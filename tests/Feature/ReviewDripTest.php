<?php

use App\Enums\MessageStatus;
use App\Enums\OptOutSource;
use App\Enums\PaymentMethod;
use App\Enums\TemplateKey;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\ReviewRequest;
use App\Services\JobLifecycle;
use App\Services\Reviews\ReviewDripService;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    Entity::query()->update(['google_review_url' => 'https://g.page/r/test-review']);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->tech = Employee::create([
        'name' => 'Tech', 'email' => 'tech@test.local', 'active' => true,
        'job_functions' => ['technician'], 'phone' => '+18185550000',
    ]);

    $this->lifecycle = app(JobLifecycle::class);
    $this->drips = app(ReviewDripService::class);
});

function makeReviewableJob($test): Job
{
    $job = $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Alan',
        'quote_amount_cents' => 45000,
    ], $test->actor);
    $test->lifecycle->assignTechnician($job, $test->tech->id, $test->actor);

    return $job->refresh();
}

/** Pays, then marks the job work-complete — opening the awaiting_call row. */
function completeReviewableJob($test, Job $job): Job
{
    $test->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $test->actor);
    $test->lifecycle->markWorkComplete($job->refresh(), $test->actor);

    return $job->refresh();
}

/** Completes the job, then logs the first call so the drip is ACTIVE with text 1 sent. */
function activateDrip($test, Job $job, string $outcome = ReviewRequest::FIRST_CALL_NO_ANSWER): ReviewRequest
{
    completeReviewableJob($test, $job);
    $request = ReviewRequest::where('job_id', $job->id)->first();
    $test->drips->logFirstCall($request, $outcome, true, $test->actor);

    return $request->refresh();
}

function reviewRowFor(Job $job, TemplateKey $key)
{
    return $job->scheduledMessages()->where('template_key', $key->value)->first();
}

it('opens awaiting_call the instant work is marked complete', function () {
    $job = makeReviewableJob($this);
    completeReviewableJob($this, $job);

    $request = ReviewRequest::where('job_id', $job->id)->first();
    expect($request)->not->toBeNull()
        ->and($request->status)->toBe(ReviewRequest::STATUS_AWAITING_CALL)
        ->and($request->sentiment)->toBeNull()
        ->and($request->step1_sent_at)->toBeNull();

    // Nothing has been texted yet — the first call hasn't been logged.
    expect(reviewRowFor($job, TemplateKey::ReviewRequest1))->toBeNull()
        ->and(reviewRowFor($job, TemplateKey::ReviewConnected))->toBeNull();
});

it('does not open a review request at payment — only at completion', function () {
    $job = makeReviewableJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);

    expect(ReviewRequest::where('job_id', $job->id)->exists())->toBeFalse();
});

it('does not open a second review request when work complete somehow runs twice', function () {
    $job = makeReviewableJob($this);
    completeReviewableJob($this, $job);
    $this->lifecycle->markWorkComplete($job->refresh(), $this->actor);

    expect(ReviewRequest::where('job_id', $job->id)->count())->toBe(1);
});

it('connected first call + thumbs up sends review_connected, not review_request_1', function () {
    $job = makeReviewableJob($this);
    completeReviewableJob($this, $job);
    $request = ReviewRequest::where('job_id', $job->id)->first();

    $this->drips->logFirstCall($request, ReviewRequest::FIRST_CALL_CONNECTED, true, $this->actor);

    $request->refresh();
    expect($request->status)->toBe(ReviewRequest::STATUS_ACTIVE)
        ->and($request->sentiment)->toBe('up')
        ->and($request->first_call_outcome)->toBe(ReviewRequest::FIRST_CALL_CONNECTED)
        ->and($request->step1_template)->toBe(TemplateKey::ReviewConnected->value)
        ->and($request->next_send_at)->not->toBeNull();

    expect(reviewRowFor($job, TemplateKey::ReviewConnected))->not->toBeNull()
        ->and(reviewRowFor($job, TemplateKey::ReviewRequest1))->toBeNull();
});

it('no-answer first call + thumbs up sends the plain review_request_1', function () {
    $job = makeReviewableJob($this);
    completeReviewableJob($this, $job);
    $request = ReviewRequest::where('job_id', $job->id)->first();

    $this->drips->logFirstCall($request, ReviewRequest::FIRST_CALL_NO_ANSWER, true, $this->actor);

    $request->refresh();
    expect($request->step1_template)->toBe(TemplateKey::ReviewRequest1->value);
    expect(reviewRowFor($job, TemplateKey::ReviewRequest1))->not->toBeNull()
        ->and(reviewRowFor($job, TemplateKey::ReviewConnected))->toBeNull();
});

it('first call + thumbs down skips the drip — nothing is sent', function (string $outcome) {
    $job = makeReviewableJob($this);
    completeReviewableJob($this, $job);
    $request = ReviewRequest::where('job_id', $job->id)->first();

    $this->drips->logFirstCall($request, $outcome, false, $this->actor);

    $request->refresh();
    expect($request->status)->toBe(ReviewRequest::STATUS_SKIPPED)
        ->and($request->sentiment)->toBe('down')
        ->and($request->step1_sent_at)->toBeNull()
        ->and(reviewRowFor($job, TemplateKey::ReviewRequest1))->toBeNull()
        ->and(reviewRowFor($job, TemplateKey::ReviewConnected))->toBeNull();
})->with([
    'connected' => [ReviewRequest::FIRST_CALL_CONNECTED],
    'no answer' => [ReviewRequest::FIRST_CALL_NO_ANSWER],
]);

it('skip for later leaves the row awaiting_call and sends nothing', function () {
    $job = makeReviewableJob($this);
    completeReviewableJob($this, $job);
    $request = ReviewRequest::where('job_id', $job->id)->first();

    $this->drips->logFirstCall($request, 'skip', null, $this->actor);

    $request->refresh();
    expect($request->status)->toBe(ReviewRequest::STATUS_AWAITING_CALL)
        ->and($request->first_call_skipped_at)->not->toBeNull()
        ->and($request->first_call_outcome)->toBeNull()
        ->and(reviewRowFor($job, TemplateKey::ReviewRequest1))->toBeNull();
});

it('sends text 2 when due, then opens the call gate 2 days later', function () {
    $job = makeReviewableJob($this);
    $request = activateDrip($this, $job);
    $request->update(['next_send_at' => now()->subMinute()]);

    $this->artisan('reviews:advance')->assertSuccessful();

    $request->refresh();
    // Text 2 is out, but the manual-call gate stays shut for call_after_days.
    expect($request->step2_sent_at)->not->toBeNull()
        ->and($request->next_send_at)->toBeNull()
        ->and($request->isCallNeeded())->toBeFalse()
        ->and(reviewRowFor($job, TemplateKey::ReviewRequest2))->not->toBeNull();

    // Backdate text 2 past the delay → the call gate opens.
    $request->update(['step2_sent_at' => now()->subDays(2)->subMinute()]);
    expect($request->refresh()->isCallNeeded())->toBeTrue()
        ->and(ReviewRequest::callNeeded()->whereKey($request->id)->exists())->toBeTrue();
});

it('voicemail call sends template 3 and schedules the final a week out', function () {
    $job = makeReviewableJob($this);
    $request = activateDrip($this, $job);
    $request->update(['step2_sent_at' => now(), 'next_send_at' => null]);

    $this->drips->logCall($request->refresh(), ReviewRequest::OUTCOME_VOICEMAIL, null, $this->actor);

    $request->refresh();
    expect($request->call_outcome)->toBe('voicemail')
        ->and($request->step3_sent_at)->not->toBeNull()
        ->and($request->step3_template)->toBe(TemplateKey::ReviewVoicemail->value)
        ->and($request->next_send_at->diffInDays(now()->addDays(7)))->toBeLessThan(1)
        ->and(reviewRowFor($job, TemplateKey::ReviewVoicemail))->not->toBeNull();
});

it('connected call + send text sends the after-call template', function () {
    $job = makeReviewableJob($this);
    $request = activateDrip($this, $job);
    $request->update(['step2_sent_at' => now(), 'next_send_at' => null]);

    $this->drips->logCall($request->refresh(), ReviewRequest::OUTCOME_CONNECTED, 'send_text', $this->actor);

    $request->refresh();
    expect($request->step3_template)->toBe(TemplateKey::ReviewAfterCall->value)
        ->and(reviewRowFor($job, TemplateKey::ReviewAfterCall))->not->toBeNull();
});

it('connected call + not interested cancels the drip', function () {
    $job = makeReviewableJob($this);
    $request = activateDrip($this, $job);
    $request->update(['step2_sent_at' => now(), 'next_send_at' => null]);

    $this->drips->logCall($request->refresh(), ReviewRequest::OUTCOME_CONNECTED, 'not_interested', $this->actor);

    $request->refresh();
    expect($request->status)->toBe(ReviewRequest::STATUS_CANCELED)
        ->and($request->cancel_reason)->toContain('not interested')
        ->and(reviewRowFor($job, TemplateKey::ReviewAfterCall))->toBeNull();
});

it('sends the final text a week after text 3 and completes the drip', function () {
    $job = makeReviewableJob($this);
    $request = activateDrip($this, $job);
    $request->update([
        'step2_sent_at' => now(),
        'call_outcome' => ReviewRequest::OUTCOME_VOICEMAIL,
        'call_logged_at' => now(),
        'step3_template' => TemplateKey::ReviewVoicemail->value,
        'step3_sent_at' => now(),
        'next_send_at' => now()->subMinute(),
    ]);

    $this->artisan('reviews:advance')->assertSuccessful();

    $request->refresh();
    expect($request->final_sent_at)->not->toBeNull()
        ->and($request->status)->toBe(ReviewRequest::STATUS_COMPLETED)
        ->and(reviewRowFor($job, TemplateKey::ReviewFinal))->not->toBeNull();
});

it('marking review left sends the thank-you and stops everything else', function () {
    $job = makeReviewableJob($this);
    $request = activateDrip($this, $job);

    $this->drips->markReviewLeft($request, $this->actor);

    $request->refresh();
    expect($request->status)->toBe(ReviewRequest::STATUS_REVIEW_LEFT)
        ->and($request->thank_you_sent_at)->not->toBeNull()
        ->and($request->next_send_at)->toBeNull()
        ->and(reviewRowFor($job, TemplateKey::ReviewThankYou))->not->toBeNull();
});

it('an inbound STOP cancels a live drip and its queued texts', function () {
    $job = makeReviewableJob($this);
    $request = activateDrip($this, $job);

    $this->lifecycle->optOutCustomer($job->customer, OptOutSource::InboundSms, 'STOP');

    $request->refresh();
    expect($request->status)->toBe(ReviewRequest::STATUS_CANCELED)
        ->and($request->cancel_reason)->toBe('customer opted out');

    // Text 1 was queued before the STOP; it must be canceled, not sent.
    $text1 = reviewRowFor($job, TemplateKey::ReviewRequest1);
    expect($text1->refresh()->status)->toBe(MessageStatus::Canceled);
});

it('an inbound STOP cancels an awaiting_call row before any call is even logged', function () {
    $job = makeReviewableJob($this);
    completeReviewableJob($this, $job);
    $request = ReviewRequest::where('job_id', $job->id)->first();

    $this->lifecycle->optOutCustomer($job->customer, OptOutSource::InboundSms, 'STOP');

    expect($request->refresh()->status)->toBe(ReviewRequest::STATUS_CANCELED);
});

it('pause holds the drip and resume picks it back up', function () {
    $job = makeReviewableJob($this);
    $request = activateDrip($this, $job);
    $request->update(['next_send_at' => now()->subMinute()]);

    $this->drips->pause($request->refresh(), $this->actor);
    $this->artisan('reviews:advance')->assertSuccessful();
    expect($request->refresh()->step2_sent_at)->toBeNull();

    $this->drips->resume($request, $this->actor);
    $this->artisan('reviews:advance')->assertSuccessful();
    expect($request->refresh()->step2_sent_at)->not->toBeNull();
});

it('holds text 1 until the entity has a Google review URL, then retries', function () {
    Entity::query()->update(['google_review_url' => null]);

    $job = makeReviewableJob($this);
    completeReviewableJob($this, $job);
    $request = ReviewRequest::where('job_id', $job->id)->first();
    $this->drips->logFirstCall($request, ReviewRequest::FIRST_CALL_NO_ANSWER, true, $this->actor);

    $request->refresh();
    expect($request->status)->toBe(ReviewRequest::STATUS_ACTIVE)
        ->and($request->step1_sent_at)->toBeNull()
        ->and(reviewRowFor($job, TemplateKey::ReviewRequest1))->toBeNull();

    // Once the URL is set, the cron retry sends text 1.
    Entity::query()->update(['google_review_url' => 'https://g.page/r/test-review']);
    $this->artisan('reviews:advance')->assertSuccessful();

    expect($request->refresh()->step1_sent_at)->not->toBeNull();
});

it('rolls text 2 forward when the next day is a Sunday', function () {
    // Saturday, 2026-07-04 → the next-day follow-up would land on Sun 7/5;
    // it must skip to Mon 7/6.
    Carbon::setTestNow(Carbon::parse('2026-07-04 12:00:00', 'America/Los_Angeles'));

    $job = makeReviewableJob($this);
    $request = activateDrip($this, $job);
    $localSend = $request->next_send_at->copy()->setTimezone('America/Los_Angeles');

    expect($localSend->isSunday())->toBeFalse()
        ->and($localSend->toDateString())->toBe('2026-07-06');

    Carbon::setTestNow();
});

it('rolls text 2 forward when the next day is a tagged holiday', function () {
    // Annual holiday on Aug 13. From Wed 2026-08-12 the follow-up would land on
    // Thu 8/13 (the holiday); it must skip to Fri 8/14.
    \App\Models\Holiday::create(['label' => 'Test Day', 'type' => \App\Models\Holiday::TYPE_ANNUAL, 'month' => 8, 'day' => 13]);
    Carbon::setTestNow(Carbon::parse('2026-08-12 12:00:00', 'America/Los_Angeles'));

    $job = makeReviewableJob($this);
    $request = activateDrip($this, $job);
    $localSend = $request->next_send_at->copy()->setTimezone('America/Los_Angeles');

    expect($localSend->toDateString())->toBe('2026-08-14');

    Carbon::setTestNow();
});

it('schedules the final text off Sundays', function () {
    // Sun 2026-08-09: a voicemail call sends text 3 and schedules the final
    // +7 days out — Sun 8/16 — which must roll forward to Mon 8/17.
    Carbon::setTestNow(Carbon::parse('2026-08-09 09:00:00', 'America/Los_Angeles'));

    $job = makeReviewableJob($this);
    $request = activateDrip($this, $job);
    $request->update(['step2_sent_at' => now(), 'next_send_at' => null]);

    $this->drips->logCall($request->refresh(), ReviewRequest::OUTCOME_VOICEMAIL, null, $this->actor);

    $request->refresh();
    $localFinal = $request->next_send_at->copy()->setTimezone('America/Los_Angeles');

    expect($localFinal->isSunday())->toBeFalse()
        ->and($localFinal->toDateString())->toBe('2026-08-17');

    Carbon::setTestNow();
});

it('renders the entity review URLs as template placeholders', function () {
    Entity::query()->update(['yelp_review_url' => 'https://yelp.com/biz/test']);
    \App\Models\MessageTemplate::where('key', TemplateKey::ReviewRequest2->value)
        ->update(['body' => 'Google: {google_review_url} Yelp: {yelp_review_url}']);

    $job = makeReviewableJob($this);
    $body = app(\App\Services\Messaging\TemplateRenderer::class)
        ->renderForJob(TemplateKey::ReviewRequest2, $job);

    expect($body)->toContain('https://g.page/r/test-review')
        ->and($body)->toContain('https://yelp.com/biz/test');
});
