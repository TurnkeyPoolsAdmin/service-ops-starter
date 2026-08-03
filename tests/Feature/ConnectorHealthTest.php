<?php

use App\Models\ConnectorHealth;
use App\Models\Setting;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Mid-morning Pacific — the POST is held outside App\Support\AlertWindow.
    Carbon::setTestNow(Carbon::parse('2026-07-13 09:00', 'America/Los_Angeles'));
    $this->seed([RoleSeeder::class, SettingSeeder::class]);
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Cache::flush();
});

afterEach(fn () => Carbon::setTestNow());

/** Configure Podium and point its token endpoint at a given response. */
function podiumConfigured(): void
{
    Setting::put('podium_client_id', 'cid');
    Setting::put('podium_client_secret', 'secret', sensitive: true);
    Setting::put('podium_refresh_token', 'refresh', sensitive: true);
}

function telegramOk(): array
{
    return ['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)];
}

it('alerts when a configured connector is rejected', function () {
    podiumConfigured();
    Http::fake(telegramOk() + ['*/token*' => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->artisan('connectors:health-check')->assertSuccessful();

    expect(ConnectorHealth::where('connector', 'podium')->first())
        ->status->toBe('down')
        ->last_alerted_status->toBe('down');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage')
        && str_contains($r['text'], 'Connector down')
        && str_contains($r['text'], 'Podium')
        && str_contains($r['text'], 'Reconnect Podium'));
});

it('does not re-alert while the connector stays down', function () {
    podiumConfigured();
    Http::fake(telegramOk() + ['*/token*' => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->artisan('connectors:health-check')->assertSuccessful();
    $firstCount = count(Http::recorded(fn ($r) => str_contains($r->url(), '/sendMessage')));

    $this->artisan('connectors:health-check')->assertSuccessful();
    $secondCount = count(Http::recorded(fn ($r) => str_contains($r->url(), '/sendMessage')));

    expect($firstCount)->toBe(1)->and($secondCount)->toBe(1);
});

it('announces a recovery once the connector works again', function () {
    podiumConfigured();

    // One stub whose behaviour flips between runs — re-calling Http::fake()
    // would MERGE with the first stub, which then keeps winning the match.
    $rejecting = true;
    Http::fake(telegramOk() + [
        '*/token*' => function () use (&$rejecting) {
            return $rejecting
                ? Http::response(['error' => 'invalid_grant'], 400)
                : Http::response(['access_token' => 'good'], 200);
        },
    ]);

    $this->artisan('connectors:health-check')->assertSuccessful();

    $rejecting = false;
    Cache::flush();
    $this->artisan('connectors:health-check')->assertSuccessful();

    expect(ConnectorHealth::where('connector', 'podium')->first())
        ->status->toBe('ok')
        ->last_alerted_status->toBe('ok');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage')
        && str_contains($r['text'], 'Connector restored'));
});

it('stays silent for a connector that was never configured', function () {
    Http::fake(telegramOk());

    $this->artisan('connectors:health-check')->assertSuccessful();

    expect(ConnectorHealth::count())->toBe(0);
    Http::assertNothingSent();
});

it('records a healthy connector as the baseline without announcing it', function () {
    podiumConfigured();
    Http::fake(telegramOk() + ['*/token*' => Http::response(['access_token' => 'good'], 200)]);

    $this->artisan('connectors:health-check')->assertSuccessful();

    expect(ConnectorHealth::where('connector', 'podium')->first())
        ->status->toBe('ok')
        ->last_alerted_status->toBe('ok');

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/sendMessage'));
});

it('retries the alert next run when the Telegram post fails', function () {
    podiumConfigured();
    Http::fake([
        '*/sendMessage' => Http::response('nope', 500),
        '*/token*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $this->artisan('connectors:health-check')->assertSuccessful();

    // Down was recorded, but never announced — so it is still pending.
    expect(ConnectorHealth::where('connector', 'podium')->first())
        ->status->toBe('down')
        ->last_alerted_status->toBeNull();
});

it('stays quiet when telegram alerts are switched off', function () {
    podiumConfigured();
    Setting::put('telegram_alerts_enabled', '0');
    Http::fake(telegramOk() + ['*/token*' => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->artisan('connectors:health-check')->assertSuccessful();

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/sendMessage'));
});

it('flags a rejected Stripe key', function () {
    Setting::put('stripe_secret_key', 'sk_test_bad', sensitive: true);
    Http::fake(telegramOk() + ['*/v1/balance' => Http::response(['error' => []], 401)]);

    $this->artisan('connectors:health-check')->assertSuccessful();

    expect(ConnectorHealth::where('connector', 'stripe')->first()->status)->toBe('down');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage')
        && str_contains($r['text'], 'Stripe'));
});
