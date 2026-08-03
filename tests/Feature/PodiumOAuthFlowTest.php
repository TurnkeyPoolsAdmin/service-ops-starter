<?php

use App\Models\Employee;
use App\Models\Setting;
use App\Services\Podium\PodiumApi;
use App\Services\Podium\PodiumOAuth;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    $this->seed(RoleSeeder::class);
    $this->owner = Employee::create([
        'name' => 'Owner', 'email' => 'owner@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['manager'],
    ])->assignRole('super_admin');

    Setting::put('podium_client_id', 'cid');
    Setting::put('podium_client_secret', 'sec', sensitive: true);
});

it('builds an authorize URL with the configured scopes', function () {
    $url = app(PodiumOAuth::class)->authorizeUrl('https://app.test/cb', 'state123');

    expect($url)->toContain('client_id=cid')
        ->and($url)->toContain('response_type=code')
        ->and($url)->toContain('scope='.urlencode('read_messages write_messages read_locations'))
        ->and($url)->toContain('state=state123');
});

it('exchanges an authorization code and stores the refresh token', function () {
    Http::fake(['*/oauth/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt-1'], 200)]);

    $ok = app(PodiumOAuth::class)->exchangeAuthorizationCode('the-code', 'https://app.test/cb');

    expect($ok)->toBeTrue()
        ->and(Setting::get('podium_refresh_token'))->toBe('rt-1');
    Http::assertSent(fn ($r) => $r['grant_type'] === 'authorization_code' && $r['code'] === 'the-code');
});

it('lists locations and registers the webhook through the API', function () {
    Setting::put('podium_refresh_token', 'rt-1', sensitive: true);
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt-1'], 200),
        '*/v4/locations' => Http::response(['data' => [['uid' => 'loc-9', 'organizationUid' => 'org-9', 'name' => 'Sales']]], 200),
        '*/v4/webhooks' => Http::response(['uid' => 'wh-1'], 201),
    ]);

    $api = app(PodiumApi::class);
    expect($api->listLocations()[0]['uid'])->toBe('loc-9');

    $created = $api->createMessageWebhook('https://app.test/webhooks/podium', 'secret', 'loc-9');
    expect($created['uid'])->toBe('wh-1');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/v4/webhooks')
        && $r['locationUid'] === 'loc-9'
        && in_array('message.received', $r['eventTypes'], true));
});

it('the callback links the location and registers the webhook end to end', function () {
    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt-1'], 200),
        '*/v4/locations' => Http::response(['data' => [['uid' => 'loc-9', 'organizationUid' => 'org-9']]], 200),
        '*/v4/webhooks' => Http::response(['uid' => 'wh-1'], 201),
    ]);

    $this->actingAs($this->owner)
        ->withSession(['podium_oauth_state' => 'st'])
        ->get('/podium/oauth/callback?code=abc&state=st')
        ->assertRedirect();

    expect(Setting::get('podium_location_uid'))->toBe('loc-9')
        ->and(Setting::get('podium_refresh_token'))->toBe('rt-1')
        ->and(Setting::get('podium_webhook_secret'))->not->toBeNull();
});

it('the callback rejects a mismatched state', function () {
    $this->actingAs($this->owner)
        ->withSession(['podium_oauth_state' => 'expected'])
        ->get('/podium/oauth/callback?code=abc&state=forged')
        ->assertRedirect();

    expect(Setting::get('podium_refresh_token'))->toBeNull();
});
