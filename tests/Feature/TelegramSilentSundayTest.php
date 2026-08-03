<?php

/**
 * Sunday is a SILENT DAY for the Telegram group chat (owner's call, 2026-08-03).
 *
 * We are closed Sunday (App\Support\ClosedDays), so nobody acts on an alert and
 * the whole day stays quiet — the three ops digests, the interval nags, the
 * weekly digests, and the inline one-shots alike.
 *
 * ⚠️ This is NOT the 7am-7pm quiet-hours rule (TelegramAlertWindowTest). Quiet
 * hours only ever DEFER a repeating alert; a silent day also DROPS the one-shot
 * alerts, because there is no later run that day to carry them. The tests below
 * check both halves: nothing posts on Sunday, and the repeating alerts still
 * name the same work on Monday.
 */

use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\Setting;
use App\Services\JobLifecycle;
use App\Services\Telegram\NewJobBookedAlert;
use App\Services\Telegram\OpsDigest;
use App\Services\Telegram\TelegramNotifier;
use App\Support\AlertWindow;
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

    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');

    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->lifecycle = app(JobLifecycle::class);
});

afterEach(fn () => Carbon::setTestNow());

/** Mid-morning — well inside the 7am-7pm window, so only the DAY is in play. */
function silentDayAt(string $localDate): void
{
    Carbon::setTestNow(Carbon::parse($localDate.' 09:00', 'America/Los_Angeles'));
}

function silentDayLead($test, string $firstName = 'Alan'): Job
{
    return $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => $firstName,
        'quote_amount_cents' => 45000,
    ], $test->actor);
}

// ---- The rule itself ----

it('reads Sunday as silent and a weekday as not', function () {
    silentDayAt('2026-08-02'); // Sunday
    expect(AlertWindow::silentToday())->toBeTrue();

    silentDayAt('2026-08-03'); // Monday
    expect(AlertWindow::silentToday())->toBeFalse();

    silentDayAt('2026-08-08'); // Saturday — we work Saturdays
    expect(AlertWindow::silentToday())->toBeFalse();
});

it('shuts the alert window all Sunday, whatever the hour', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-02 12:00', 'America/Los_Angeles'));
    expect(AlertWindow::open())->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-08-03 12:00', 'America/Los_Angeles'));
    expect(AlertWindow::open())->toBeTrue();
});

it('reads the day off local Pacific time, not stored UTC', function () {
    // 5pm Sunday Pacific is already Monday 00:00 UTC. A UTC reader would call
    // this a working day and post into the closed-day chat.
    Carbon::setTestNow(Carbon::parse('2026-08-02 17:00', 'America/Los_Angeles'));

    expect(now()->utc()->format('l'))->toBe('Monday')
        ->and(AlertWindow::silentToday())->toBeTrue();
});

// ---- The choke point: every alert goes through TelegramNotifier::send() ----

it('sends nothing at all on a Sunday, and says so without calling it a failure', function () {
    silentDayAt('2026-08-02');

    $result = app(TelegramNotifier::class)->send('Anything at all.');

    expect($result->success)->toBeFalse()
        ->and($result->suppressed)->toBeTrue()
        ->and($result->errorCode)->toBe('silent_day');
    Http::assertNothingSent();
});

it('sends normally the next morning', function () {
    silentDayAt('2026-08-03');

    expect(app(TelegramNotifier::class)->send('Anything at all.')->success)->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r['text'], 'Anything at all.'));
});

it('still posts the hand-pressed test message on a Sunday', function () {
    silentDayAt('2026-08-02');

    // A human is watching the Integrations page — a held test reads as a broken bot.
    expect(app(TelegramNotifier::class)->send('Test message.', force: true)->success)->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r['text'], 'Test message.'));
});

it('alerts every day again when the silent days are cleared', function () {
    config()->set('glass.telegram.silent_days', '');
    silentDayAt('2026-08-02');

    expect(AlertWindow::silentToday())->toBeFalse()
        ->and(app(TelegramNotifier::class)->send('Sunday post.')->success)->toBeTrue();
});

// ---- Repeating alerts: quiet Sunday, and NOTHING IS LOST on Monday ----

it('holds the ops digest on Sunday and posts the same work on Monday', function () {
    silentDayAt('2026-08-02');
    silentDayLead($this, 'Alan');

    // The digest has something to say — it is the day that holds it, not an
    // empty queue.
    expect(app(OpsDigest::class)->buildMessages('morning'))->not->toBeEmpty();

    $this->artisan('alerts:telegram-digest --when=morning')->assertSuccessful();
    Http::assertNothingSent();

    silentDayAt('2026-08-03');
    $this->artisan('alerts:telegram-digest --when=morning')->assertSuccessful();

    Http::assertSent(fn ($r) => str_contains($r['text'], 'Alan'));
});

it('holds the weekly Xpro digest when its day lands on a Sunday', function () {
    // The Friday/Wednesday digests are fixed-day, but the command can be run by
    // hand any day — the gate is the day it actually runs, not its schedule.
    silentDayAt('2026-08-02');

    $this->artisan('alerts:telegram-xpro-weekly')
        ->expectsOutputToContain('off today')
        ->assertSuccessful();
    Http::assertNothingSent();
});

// ---- One-shot alerts: dropped for the day, on purpose ----

it('drops the inline new-job alert on a Sunday instead of posting it late', function () {
    silentDayAt('2026-08-02');
    $job = silentDayLead($this, 'Sunday Lead');

    app(NewJobBookedAlert::class)->sendFor($job, autoIntakeEnabled: false);
    Http::assertNothingSent();

    // The alert fires inline at creation and is never re-run, so Monday brings
    // no catch-up post. The lead is still on the board — that is the trade.
    silentDayAt('2026-08-03');
    Http::assertNothingSent();
});
