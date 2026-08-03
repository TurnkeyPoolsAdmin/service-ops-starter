<?php

use App\Enums\IssueType;
use App\Enums\PaymentMethod;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Issue;
use App\Models\Job;
use App\Models\Setting;
use App\Services\JobLifecycle;
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
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Build an Xpro-collected job whose payment lands at $collectedAt (local),
 * which is what starts the collection deadline clock.
 */
function overdueXproJob($test, string $collectedAt = '2026-07-13 12:00', PaymentMethod $method = PaymentMethod::KlarnaXpro): Job
{
    Carbon::setTestNow(Carbon::parse($collectedAt, 'America/Los_Angeles'));

    $job = $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+1818555'.random_int(1000, 9999),
        'first_name' => 'Alan',
        'quote_amount_cents' => 45000,
    ], $test->actor);

    $test->lifecycle->schedule(
        $job,
        Carbon::parse($collectedAt, 'America/Los_Angeles'),
        $test->actor,
        technicianId: $test->tech->id,
    );

    $job->refresh()->update(['work_completed_at' => now()]);
    $test->lifecycle->recordPayment($job->refresh(), 45000, $method, null, null, $test->actor);

    return $job->refresh();
}

// ---- The deadline math ----

it('is due at noon the Wednesday after the first Friday on/after the collection day', function () {
    // Collected Mon Jul 13 -> first Friday Jul 17 -> due Wed Jul 22 at 12pm PT.
    $job = overdueXproJob($this, '2026-07-13 12:00');

    expect($job->xproCollectionDueAt()->format('Y-m-d'))->toBe('2026-07-22')
        ->and($job->xproCollectionDueAt()->format('H:i'))->toBe('12:00');
});

it('is NOT overdue before noon on the deadline Wednesday', function () {
    $job = overdueXproJob($this, '2026-07-13 12:00');

    Carbon::setTestNow(Carbon::parse('2026-07-22 09:00', 'America/Los_Angeles')); // Wed morning
    expect($job->refresh()->isXproCollectionOverdue())->toBeFalse();
});

it('is overdue once noon on the deadline Wednesday arrives', function () {
    $job = overdueXproJob($this, '2026-07-13 12:00');

    Carbon::setTestNow(Carbon::parse('2026-07-22 12:30', 'America/Los_Angeles')); // Wed afternoon
    expect($job->refresh()->isXproCollectionOverdue())->toBeTrue();
});

it('is never overdue once the money has been collected back', function () {
    $job = overdueXproJob($this, '2026-07-13 12:00');
    $this->lifecycle->markXproCollected($job, $this->actor);

    Carbon::setTestNow(Carbon::parse('2026-07-29 09:00', 'America/Los_Angeles'));
    expect($job->refresh()->isXproCollectionOverdue())->toBeFalse();
});

// ---- Issues sweep ----

it('raises an xpro_uncollected issue for an overdue job', function () {
    $job = overdueXproJob($this, '2026-07-13 12:00');

    Carbon::setTestNow(Carbon::parse('2026-07-22 12:30', 'America/Los_Angeles'));
    $this->artisan('issues:sweep')->assertSuccessful();

    $issue = Issue::open()->where('type', IssueType::XproUncollected->value)
        ->where('subject_id', $job->id)->first();

    expect($issue)->not->toBeNull()
        ->and($issue->details['job_number'])->toBe($job->job_number)
        ->and($issue->details['xpro_owed_cents'])->toBe(45000)
        ->and($issue->details['due_by'])->toBe('2026-07-22');
});

it('does NOT raise an xpro_uncollected issue before the deadline', function () {
    $job = overdueXproJob($this, '2026-07-13 12:00');

    Carbon::setTestNow(Carbon::parse('2026-07-21 09:00', 'America/Los_Angeles')); // Tue
    $this->artisan('issues:sweep')->assertSuccessful();

    expect(Issue::open()->where('type', IssueType::XproUncollected->value)->where('subject_id', $job->id)->exists())
        ->toBeFalse();
});

it('clears the xpro_uncollected issue once the money is collected back', function () {
    $job = overdueXproJob($this, '2026-07-13 12:00');

    Carbon::setTestNow(Carbon::parse('2026-07-22 12:30', 'America/Los_Angeles'));
    $this->artisan('issues:sweep');
    expect(Issue::open()->where('type', IssueType::XproUncollected->value)->where('subject_id', $job->id)->exists())->toBeTrue();

    $this->lifecycle->markXproCollected($job->refresh(), $this->actor);

    $this->artisan('issues:sweep');
    expect(Issue::open()->where('type', IssueType::XproUncollected->value)->where('subject_id', $job->id)->exists())->toBeFalse();
});

it('does not duplicate the xpro_uncollected issue across sweeps', function () {
    $job = overdueXproJob($this, '2026-07-13 12:00');

    Carbon::setTestNow(Carbon::parse('2026-07-22 12:30', 'America/Los_Angeles'));
    $this->artisan('issues:sweep');
    $this->artisan('issues:sweep');

    expect(Issue::where('type', IssueType::XproUncollected->value)->where('subject_id', $job->id)->count())->toBe(1);
});

// ---- Telegram escalation ----

it('posts the overdue Xpro chase list to Telegram every Wednesday', function () {
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 7]], 200)]);

    $job = overdueXproJob($this, '2026-07-13 12:00');

    Carbon::setTestNow(Carbon::parse('2026-07-22 12:30', 'America/Los_Angeles'));
    $this->artisan('alerts:telegram-xpro-overdue')->assertSuccessful();

    Http::assertSent(fn ($req) => str_contains($req->url(), '/bottest-token/sendMessage')
        && str_contains($req['text'], 'OVERDUE')
        && str_contains($req['text'], 'Alan'));

    // Stamped as "last chased", but that stamp no longer gates the send.
    expect($job->refresh()->xpro_overdue_alerted_at)->not->toBeNull();

    // Next Wednesday, still uncollected → it reposts rather than going quiet.
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 8]], 200)]);
    Carbon::setTestNow(Carbon::parse('2026-07-29 12:30', 'America/Los_Angeles'));
    $this->artisan('alerts:telegram-xpro-overdue')->assertSuccessful();

    Http::assertSent(fn ($req) => str_contains($req['text'], 'Alan'));
});

it('drops a job off the overdue Xpro chase list once collected', function () {
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Http::fake();

    $job = overdueXproJob($this, '2026-07-13 12:00');

    Carbon::setTestNow(Carbon::parse('2026-07-22 12:30', 'America/Los_Angeles'));
    $this->lifecycle->markXproCollected($job->refresh(), $this->actor);

    $this->artisan('alerts:telegram-xpro-overdue')->assertSuccessful();

    Http::assertNothingSent();
});

it('stays quiet on the overdue escalation when nothing is overdue', function () {
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Http::fake();

    overdueXproJob($this, '2026-07-13 12:00');

    // Still before the deadline — nothing to escalate.
    Carbon::setTestNow(Carbon::parse('2026-07-21 09:00', 'America/Los_Angeles'));
    $this->artisan('alerts:telegram-xpro-overdue')->assertSuccessful();

    Http::assertNothingSent();
});
