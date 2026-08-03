<?php

use App\Enums\PaymentMethod;
use App\Models\Employee;
use App\Models\Entity;
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

    // A fixed weekday morning so parts-ready cutoffs are deterministic. 8:30am
    // is deliberately INSIDE the 7am-7pm overdue-alert window — the overdue
    // sweep is a silent no-op outside it (see OverdueCompletionAlert).
    Carbon::setTestNow(Carbon::parse('2026-07-13 08:30', 'America/Los_Angeles')); // Monday

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

    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
});

afterEach(fn () => Carbon::setTestNow());

function needsSchedulingJob($test, string $firstName, ?string $requestedDay = null, string $phone = '+18185551234'): void
{
    $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => $phone,
        'first_name' => $firstName,
        'quote_amount_cents' => 45000,
        'requested_day' => $requestedDay,
    ], $test->actor);
}

function partsReadyJob($test, string $firstName): void
{
    $job = $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'SFV')->value('id'),
        'phone_e164' => '+18185555678',
        'first_name' => $firstName,
        'quote_amount_cents' => 45000,
        'parts_json' => [['label' => 'FW05046 windshield', 'cost_cents' => 10000]],
    ], $test->actor);

    // Scheduled today → within the next business day → "ready to order".
    $test->lifecycle->schedule(
        $job,
        Carbon::parse('2026-07-13 09:00', 'America/Los_Angeles'),
        $test->actor,
        technicianId: $test->tech->id,
    );
}

// ---- The three daily digests ----

it('posts a digest listing both queues to the group chat', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)]);

    needsSchedulingJob($this, 'Alan');
    partsReadyJob($this, 'Bob');

    $this->artisan('alerts:telegram-digest --when=morning')->assertSuccessful();

    Http::assertSent(function ($req) {
        return str_contains($req->url(), '/bottest-token/sendMessage')
            && $req['chat_id'] === '-1009999'
            && str_contains($req['text'], 'Morning digest')
            && str_contains($req['text'], 'Needs scheduling')
            && str_contains($req['text'], 'Alan')
            && str_contains($req['text'], 'Parts')
            && str_contains($req['text'], 'Bob');
    });
});

it('splits jobs requested for today into their own URGENT message in the morning', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)]);

    // Distinct phones — same phone would dedupe onto one customer record.
    needsSchedulingJob($this, 'Urgent', '2026-07-13', '+18185550001');   // today
    needsSchedulingJob($this, 'Overdue', '2026-07-11', '+18185550002');  // a day already past
    needsSchedulingJob($this, 'Later', '2026-07-15', '+18185550003');    // later this week
    needsSchedulingJob($this, 'Undated', null, '+18185550004');          // no real requested date
    partsReadyJob($this, 'Bob');

    $this->artisan('alerts:telegram-digest --when=morning')->assertSuccessful();

    Http::assertSentCount(2);

    // 1. The urgent message — today + overdue only.
    Http::assertSent(fn ($req) => str_contains($req['text'], 'URGENT')
        && str_contains($req['text'], 'Urgent')
        && str_contains($req['text'], 'Overdue')
        && ! str_contains($req['text'], 'Later')
        && ! str_contains($req['text'], 'Undated')
        && ! str_contains($req['text'], 'Bob'));

    // 2. The digest — everything else.
    Http::assertSent(fn ($req) => str_contains($req['text'], 'Morning digest')
        && str_contains($req['text'], 'Later')
        && str_contains($req['text'], 'Undated')
        && str_contains($req['text'], 'Bob')
        && ! str_contains($req['text'], 'Urgent'));
});

it('merges the urgent jobs into the single midday digest', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)]);

    needsSchedulingJob($this, 'Urgent', '2026-07-13', '+18185550001'); // today
    needsSchedulingJob($this, 'Later', '2026-07-15', '+18185550003');  // later this week

    $this->artisan('alerts:telegram-digest --when=midday')->assertSuccessful();

    // One message, not two — and the same-day job is in it, flagged.
    Http::assertSentCount(1);
    Http::assertSent(fn ($req) => str_contains($req['text'], 'Midday digest')
        && str_contains($req['text'], '🚨 <a')
        && str_contains($req['text'], 'Urgent')
        && str_contains($req['text'], 'Later')
        && ! str_contains($req['text'], 'URGENT — needs scheduling'));
});

it('merges the urgent jobs into the single evening digest', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)]);

    needsSchedulingJob($this, 'Urgent', '2026-07-13', '+18185550001');

    $this->artisan('alerts:telegram-digest --when=evening')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($req) => str_contains($req['text'], 'Evening digest')
        && str_contains($req['text'], 'Urgent'));
});

it('stays quiet when nothing is pending', function () {
    Http::fake();

    $this->artisan('alerts:telegram-digest --when=morning')->assertSuccessful();

    Http::assertNothingSent();
});

it('rejects an unknown --when slot', function () {
    Http::fake();

    $this->artisan('alerts:telegram-digest --when=teatime')->assertFailed();

    Http::assertNothingSent();
});

it('skips sending when alerts are disabled', function () {
    Http::fake();
    Setting::put('telegram_alerts_enabled', '0');
    needsSchedulingJob($this, 'Alan');

    $this->artisan('alerts:telegram-digest --when=morning')->assertSuccessful();

    Http::assertNothingSent();
});

it('skips sending when the bot is not configured', function () {
    Http::fake();
    Setting::put('telegram_bot_token', null, sensitive: true);
    needsSchedulingJob($this, 'Alan');

    $this->artisan('alerts:telegram-digest --when=morning')->assertSuccessful();

    Http::assertNothingSent();
});

// ---- Prepay-parts section ----

/** An "Other"-supplier job scheduled for $scheduledLocal, ordered but unpaid. */
function prepayPendingJob($test, string $firstName, string $scheduledLocal, string $phone = '+18185551234'): void
{
    $job = $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => $phone,
        'first_name' => $firstName,
        'quote_amount_cents' => 45000,
        'parts_json' => [['label' => 'FW05046 windshield', 'cost_cents' => 10000]],
    ], $test->actor);

    $test->lifecycle->schedule(
        $job,
        Carbon::parse($scheduledLocal, 'America/Los_Angeles'),
        $test->actor,
        technicianId: $test->tech->id,
        warehouseOther: 'Pacific BMW',
    );

    $test->lifecycle->markPartsOrdered($job->refresh(), $test->actor);
}

it('lists unpaid Other-supplier parts for today and tomorrow, but no further out', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)]);

    prepayPendingJob($this, 'Cara', '2026-07-13 09:00', '+18185551111');   // today
    prepayPendingJob($this, 'Dana', '2026-07-14 09:00', '+18185552222');   // tomorrow
    prepayPendingJob($this, 'Farout', '2026-07-17 09:00', '+18185553333'); // 4 days out

    $this->artisan('alerts:telegram-digest --when=midday')->assertSuccessful();

    Http::assertSent(fn ($req) => str_contains($req['text'], 'Prepay these parts')
        && str_contains($req['text'], 'Cara')
        && str_contains($req['text'], 'Dana')
        && str_contains($req['text'], 'Pacific BMW')
        && ! str_contains($req['text'], 'Farout'));
});

it('drops the prepay section once the part is paid', function () {
    Http::fake();

    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Cara',
        'quote_amount_cents' => 45000,
        'parts_json' => [['label' => 'FW05046 windshield', 'cost_cents' => 10000]],
    ], $this->actor);
    $this->lifecycle->schedule($job, Carbon::parse('2026-07-13 09:00', 'America/Los_Angeles'),
        $this->actor, technicianId: $this->tech->id, warehouseOther: 'Pacific BMW');
    $this->lifecycle->markPartsOrdered($job->refresh(), $this->actor);
    $this->lifecycle->markPartsPaid($job->refresh(), $this->actor);

    $this->artisan('alerts:telegram-digest --when=midday')->assertSuccessful();

    Http::assertNothingSent();
});

// ---- Completed-but-unpaid section ----

/** A work-complete job with $collectedCents already collected (0 = unpaid). */
function completedJob($test, string $firstName, int $collectedCents = 0, string $phone = '+18185551234'): void
{
    $job = $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => $phone,
        'first_name' => $firstName,
        'quote_amount_cents' => 45000,
    ], $test->actor);

    $test->lifecycle->schedule($job, Carbon::parse('2026-07-13 09:00', 'America/Los_Angeles'),
        $test->actor, technicianId: $test->tech->id);
    $test->lifecycle->markWorkComplete($job->refresh(), $test->actor);

    if ($collectedCents > 0) {
        $test->lifecycle->recordPayment($job->refresh(), $collectedCents, PaymentMethod::Zelle, null, null, $test->actor);
    }
}

it('lists completed jobs that still owe money in every digest', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)]);

    completedJob($this, 'Owes', 0, '+18185551111');        // unpaid
    completedJob($this, 'Deposit', 20000, '+18185552222'); // partial
    completedJob($this, 'Settled', 45000, '+18185553333'); // fully paid → excluded

    foreach (['morning', 'midday', 'evening'] as $slot) {
        $this->artisan("alerts:telegram-digest --when={$slot}")->assertSuccessful();
    }

    Http::assertSentCount(3);
    Http::assertSent(fn ($req) => str_contains($req['text'], 'payment outstanding')
        && str_contains($req['text'], 'Owes')
        && str_contains($req['text'], 'Deposit')
        && ! str_contains($req['text'], 'Settled'));
});

// ---- Overdue-completion alert ----

/** A scheduled job at $scheduledLocal with a tech, never marked complete. */
function overdueJob($test, string $firstName, string $scheduledLocal, string $phone = '+18185551234')
{
    $job = $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => $phone,
        'first_name' => $firstName,
        'quote_amount_cents' => 45000,
    ], $test->actor);

    return $test->lifecycle->schedule(
        $job,
        Carbon::parse($scheduledLocal, 'America/Los_Angeles'),
        $test->actor,
        technicianId: $test->tech->id,
    );
}

it('alerts a job still not complete 1h past its window', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)]);

    // 8–10am yesterday → window + 1h grace closed at 11am yesterday, long past.
    overdueJob($this, 'Late', '2026-07-12 08:00');
    // 9am today (window 9–11) → not due until noon; it's 8:30am.
    overdueJob($this, 'Soon', '2026-07-13 09:00', '+18185559999');

    $this->artisan('alerts:telegram-overdue-jobs')->assertSuccessful();

    Http::assertSent(fn ($req) => str_contains($req['text'], 'Overdue completion')
        && str_contains($req['text'], 'Late')
        && ! str_contains($req['text'], 'Soon'));
});

it('does not repeat the overdue alert within the hour', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)]);

    overdueJob($this, 'Late', '2026-07-12 08:00');

    $this->artisan('alerts:telegram-overdue-jobs')->assertSuccessful();
    Http::assertSentCount(1);

    // 30 minutes later — the 15-min sweep runs, but the job isn't due again.
    Carbon::setTestNow(Carbon::parse('2026-07-13 09:00', 'America/Los_Angeles'));
    $this->artisan('alerts:telegram-overdue-jobs')->assertSuccessful();
    Http::assertSentCount(1);
});

it('repeats the overdue alert every hour until the job is closed', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)]);

    overdueJob($this, 'Late', '2026-07-12 08:00');

    $this->artisan('alerts:telegram-overdue-jobs')->assertSuccessful();
    Http::assertSentCount(1);

    // An hour on, still not marked complete → it says so again.
    Carbon::setTestNow(Carbon::parse('2026-07-13 09:35', 'America/Los_Angeles'));
    $this->artisan('alerts:telegram-overdue-jobs')->assertSuccessful();
    Http::assertSentCount(2);

    // And again the hour after that.
    Carbon::setTestNow(Carbon::parse('2026-07-13 10:40', 'America/Los_Angeles'));
    $this->artisan('alerts:telegram-overdue-jobs')->assertSuccessful();
    Http::assertSentCount(3);
});

it('stays silent outside the overdue alert window', function () {
    Http::fake();

    overdueJob($this, 'Late', '2026-07-12 08:00');

    // 8pm — past the 7pm cutoff, so the group isn't pinged all night.
    Carbon::setTestNow(Carbon::parse('2026-07-13 20:00', 'America/Los_Angeles'));
    $this->artisan('alerts:telegram-overdue-jobs')->assertSuccessful();

    Http::assertNothingSent();
});

it('re-arms the overdue alert when a job is rescheduled', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)]);

    $job = overdueJob($this, 'Late', '2026-07-12 08:00');

    $this->artisan('alerts:telegram-overdue-jobs')->assertSuccessful();
    Http::assertSentCount(1);

    // Pushed to a new slot that's also already overdue → alerts again at once,
    // without waiting out the repeat interval.
    $this->lifecycle->schedule($job->refresh(), Carbon::parse('2026-07-12 12:00', 'America/Los_Angeles'), $this->actor, technicianId: $this->tech->id);

    $this->artisan('alerts:telegram-overdue-jobs')->assertSuccessful();
    Http::assertSentCount(2);
});

it('overdue alert stays quiet when nothing is overdue', function () {
    Http::fake();

    overdueJob($this, 'Soon', '2026-07-13 09:00'); // later today, window still open

    $this->artisan('alerts:telegram-overdue-jobs')->assertSuccessful();

    Http::assertNothingSent();
});
