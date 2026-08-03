<?php

use App\Models\Setting;
use App\Services\Messaging\Providers\LogMessageProvider;
use App\Services\Messaging\Providers\MessageProvider;
use App\Services\Messaging\Providers\OpenPhoneProvider;
use App\Services\Messaging\Providers\PodiumProvider;
use App\Services\Podium\PodiumOAuth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    $this->app['env'] = 'production';   // real providers only resolve in prod
    Setting::put('outgoing_sms_paused', '0');
});

it('resolves Quo/OpenPhone when that provider is selected', function () {
    Setting::put('outgoing_provider', 'openphone');

    expect(app(MessageProvider::class))->toBeInstanceOf(OpenPhoneProvider::class);
});

it('resolves Podium when that provider is selected', function () {
    Setting::put('outgoing_provider', 'podium');

    expect(app(MessageProvider::class))->toBeInstanceOf(PodiumProvider::class);
});

it('falls back to the log provider when paused regardless of selection', function () {
    Setting::put('outgoing_provider', 'podium');
    Setting::put('outgoing_sms_paused', '1');

    expect(app(MessageProvider::class))->toBeInstanceOf(LogMessageProvider::class);
});

it('sends via the Podium v4 messages API with a bearer token', function () {
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'tok123', 'refresh_token' => 'rt-same'], 200),
        '*/v4/messages' => Http::response(['data' => ['uid' => 'msg-1']], 201),
    ]);
    Setting::put('podium_client_id', 'cid');
    Setting::put('podium_client_secret', 'sec', sensitive: true);
    Setting::put('podium_refresh_token', 'rt-same', sensitive: true);

    $provider = new PodiumProvider(app(PodiumOAuth::class), 'loc-uid', 'https://api.podium.com', null);
    $result = $provider->send('+18185551234', '+18185550000', 'Hello there');

    expect($result->success)->toBeTrue()
        ->and($result->providerMessageId)->toBe('msg-1');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/v4/messages')
        && $req->hasHeader('Authorization', 'Bearer tok123')
        && $req['locationUid'] === 'loc-uid'
        && $req['channel']['identifier'] === '+18185551234'
        && $req['channel']['type'] === 'phone'
        && $req['body'] === 'Hello there');
});

it('fails cleanly (no throw) when Podium has no location configured', function () {
    $provider = new PodiumProvider(app(PodiumOAuth::class), null);
    $result = $provider->send('+18185551234', '+18185550000', 'Hi');

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('config');
});

it('persists a rotated refresh token after a token refresh', function () {
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'tokA', 'refresh_token' => 'rt-NEW'], 200),
    ]);
    Setting::put('podium_client_id', 'cid');
    Setting::put('podium_client_secret', 'sec', sensitive: true);
    Setting::put('podium_refresh_token', 'rt-OLD', sensitive: true);

    $token = app(PodiumOAuth::class)->accessToken();

    expect($token)->toBe('tokA')
        ->and(Setting::get('podium_refresh_token'))->toBe('rt-NEW');
});
