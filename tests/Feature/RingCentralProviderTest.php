<?php

use App\Models\Setting;
use App\Services\Messaging\Providers\MessageProvider;
use App\Services\Messaging\Providers\RingCentralProvider;
use App\Services\RingCentral\RingCentralOAuth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    $this->app['env'] = 'production';   // real providers only resolve in prod
    Setting::put('outgoing_sms_paused', '0');
    Setting::put('ringcentral_client_id', 'cid');
    Setting::put('ringcentral_client_secret', 'sec', sensitive: true);
    Setting::put('ringcentral_jwt', 'the-jwt', sensitive: true);
});

it('resolves RingCentral when that provider is selected', function () {
    Setting::put('outgoing_provider', 'ringcentral');

    expect(app(MessageProvider::class))->toBeInstanceOf(RingCentralProvider::class);
});

it('mints an access token via the jwt-bearer grant with basic auth', function () {
    Http::fake([
        '*/restapi/oauth/token' => Http::response(['access_token' => 'rc-tok', 'expires_in' => 3600], 200),
    ]);

    $token = app(RingCentralOAuth::class)->accessToken();

    expect($token)->toBe('rc-tok');

    Http::assertSent(function ($req) {
        $expectedAuth = 'Basic '.base64_encode('cid:sec');

        return str_contains($req->url(), '/restapi/oauth/token')
            && $req->hasHeader('Authorization', $expectedAuth)
            && $req['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && $req['assertion'] === 'the-jwt';
    });
});

it('sends via the RingCentral SMS API with a bearer token', function () {
    Http::fake([
        '*/restapi/oauth/token' => Http::response(['access_token' => 'rc-tok', 'expires_in' => 3600], 200),
        '*/restapi/v1.0/account/~/extension/~/sms' => Http::response(['id' => 987654, 'messageStatus' => 'Queued'], 200),
    ]);

    $provider = new RingCentralProvider(app(RingCentralOAuth::class), '+18185550000');
    $result = $provider->send('+18185551234', '+18185550000', 'Hello from RC');

    expect($result->success)->toBeTrue()
        ->and($result->providerMessageId)->toBe('987654');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/restapi/v1.0/account/~/extension/~/sms')
        && $req->hasHeader('Authorization', 'Bearer rc-tok')
        && $req['from']['phoneNumber'] === '+18185550000'
        && $req['to'][0]['phoneNumber'] === '+18185551234'
        && $req['text'] === 'Hello from RC');
});

it('returns a clean failure (no throw) when RingCentral rejects the send', function () {
    Http::fake([
        '*/restapi/oauth/token' => Http::response(['access_token' => 'rc-tok', 'expires_in' => 3600], 200),
        '*/restapi/v1.0/account/~/extension/~/sms' => Http::response([
            'errorCode' => 'InvalidPhoneNumber', 'message' => 'Phone number is invalid',
        ], 400),
    ]);

    $provider = new RingCentralProvider(app(RingCentralOAuth::class), '+18185550000');
    $result = $provider->send('+1badnumber', '+18185550000', 'Hi');

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('InvalidPhoneNumber')
        ->and($result->errorMessage)->toBe('Phone number is invalid');
});

it('fails cleanly when no JWT credential is configured', function () {
    Setting::put('ringcentral_jwt', null);

    $provider = new RingCentralProvider(app(RingCentralOAuth::class), '+18185550000');
    $result = $provider->send('+18185551234', '+18185550000', 'Hi');

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('config');
});
