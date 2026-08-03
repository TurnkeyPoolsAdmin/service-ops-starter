<?php

/**
 * The 7am-7pm quiet-hours rule for RECURRING Telegram alerts (App\Support\AlertWindow).
 *
 * Every recurring nag runs on a bare interval — every 5 / 15 / 30 minutes, or
 * hourly — and an interval schedule knows nothing about the time of day. The
 * same-day parts nag proved it: at 00:00 Pacific the calendar day rolls over,
 * every job scheduled for the coming day whose part is not ordered becomes
 * "TODAY", and the group chat got a post every 30 minutes from midnight until
 * someone woke up.
 *
 * The point of each test below is not only that the alert stays quiet, but that
 * NOTHING IS LOST — the same alert still lands on the first run after 7am.
 */

use App\Enums\MessageType;
use App\Enums\PartsSupplier;
use App\Enums\TemplateKey;
use App\Models\ConnectorHealth;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\ScheduledMessage;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\JobLifecycle;
use App\Services\Telegram\SameDayPartsUnorderedAlert;
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

function alertWindowAt(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'America/Los_Angeles'));
}

/** A job scheduled for TODAY whose part has not been ordered. */
function windowTestJob($test, string $scheduledLocal): Job
{
    $warehouse = Warehouse::firstOrCreate(
        ['name' => 'Mygrant SFV'],
        ['supplier' => PartsSupplier::Mygrant->value, 'supplier_code' => 'B014'],
    );

    $job = $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Alan',
        'quote_amount_cents' => 45000,
        'parts_json' => [['label' => 'FW05046GTYN windshield', 'cost_cents' => 10000]],
    ], $test->actor);

    $test->lifecycle->schedule(
        $job,
        Carbon::parse($scheduledLocal, 'America/Los_Angeles'),
        $test->actor,
        technicianId: $test->tech->id,
        warehouseId: $warehouse->id,
    );

    return $job->refresh();
}

// ---- The rule itself ----

it('is open through the working day and shut overnight', function () {
    alertWindowAt('2026-07-20 07:00');
    expect(AlertWindow::open())->toBeTrue();

    alertWindowAt('2026-07-20 19:00');
    expect(AlertWindow::open())->toBeTrue();

    alertWindowAt('2026-07-20 00:00');
    expect(AlertWindow::open())->toBeFalse();

    alertWindowAt('2026-07-20 03:30');
    expect(AlertWindow::open())->toBeFalse();

    alertWindowAt('2026-07-20 06:59');
    expect(AlertWindow::open())->toBeFalse();

    alertWindowAt('2026-07-20 19:01');
    expect(AlertWindow::open())->toBeFalse();
});

it('reads the window off local Pacific time, not stored UTC', function () {
    // 08:00 Pacific is 15:00 UTC. Reading the UTC clock would work here by luck,
    // so also check the inverse: 23:00 Pacific is 06:00 UTC the next day, which
    // a UTC reader would wrongly call "inside the window".
    alertWindowAt('2026-07-20 08:00');
    expect(AlertWindow::open())->toBeTrue();

    alertWindowAt('2026-07-20 23:00');
    expect(now()->utc()->format('H:i'))->toBe('06:00')
        ->and(AlertWindow::open())->toBeFalse();
});

// ---- The alert that caused this: same-day parts unordered ----

it('does not post the same-day parts nag at midnight, and posts it at 7am', function () {
    // Midnight: the day just rolled over, so the job IS same-day and unordered.
    alertWindowAt('2026-07-20 00:00');
    $job = windowTestJob($this, '2026-07-20 14:00');

    expect($job->isPartsOrdered())->toBeFalse()
        ->and(Job::query()->partsToOrder()->scheduledToday()->count())->toBe(1)
        ->and(app(SameDayPartsUnorderedAlert::class)->build())->toBeNull();

    // Nothing was consumed by the quiet run — the same job is named at 7am.
    alertWindowAt('2026-07-20 07:00');
    expect(app(SameDayPartsUnorderedAlert::class)->build())->toContain('Alan');
});

it('stops posting the same-day parts nag after 7pm', function () {
    alertWindowAt('2026-07-20 08:00');
    windowTestJob($this, '2026-07-20 14:00');
    expect(app(SameDayPartsUnorderedAlert::class)->build())->not->toBeNull();

    alertWindowAt('2026-07-20 20:30');
    expect(app(SameDayPartsUnorderedAlert::class)->build())->toBeNull();
});

// ---- SMS delivery watchdog ----

it('holds the delivery watchdog overnight without stamping the row', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    Setting::put('outgoing_provider', 'openphone');

    alertWindowAt('2026-07-20 02:00');
    $message = ScheduledMessage::create([
        'entity_id' => Entity::where('code', 'LA')->first()->id,
        'template_key' => TemplateKey::JobComplete->value,
        'message_type' => MessageType::Completion->value,
        'to_phone_e164' => '+13105556553',
        'from_phone_e164' => '+18185550000',
        'provider' => 'openphone',
        'rendered_body' => 'Job complete body.',
        'status' => 'sent',
        'sent_at' => now()->subMinutes(20),
        'provider_message_id' => 'ACwindow1',
        'idempotency_key' => 'test:window:1',
    ]);

    $this->artisan('alerts:sms-delivery-unconfirmed')->assertSuccessful();

    // Unstamped is the whole point: an unstamped row still alerts after 7am.
    expect($message->refresh()->delivery_unconfirmed_alerted_at)->toBeNull();
    Http::assertNothingSent();

    alertWindowAt('2026-07-20 07:05');
    $this->artisan('alerts:sms-delivery-unconfirmed')->assertSuccessful();

    expect($message->refresh()->delivery_unconfirmed_alerted_at)->not->toBeNull();
    Http::assertSent(fn ($r) => str_contains($r['text'], 'not confirmed delivered'));
});

// ---- Connector health ----

it('records a connector break overnight and announces it on the 7am run', function () {
    Setting::put('podium_client_id', 'cid');
    Setting::put('podium_client_secret', 'secret', sensitive: true);
    Setting::put('podium_refresh_token', 'refresh', sensitive: true);
    Http::fake([
        '*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        '*/token*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    alertWindowAt('2026-07-20 02:00');
    $this->artisan('connectors:health-check')->assertSuccessful();

    // The probe still ran and still recorded the break; only the post is held,
    // and last_alerted_status stays null so the break is not swallowed.
    expect(ConnectorHealth::where('connector', 'podium')->first())
        ->status->toBe('down')
        ->last_alerted_status->toBeNull();
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/sendMessage'));

    alertWindowAt('2026-07-20 07:00');
    $this->artisan('connectors:health-check')->assertSuccessful();

    expect(ConnectorHealth::where('connector', 'podium')->first()->last_alerted_status)->toBe('down');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage')
        && str_contains($r['text'], 'Connector down'));
});
