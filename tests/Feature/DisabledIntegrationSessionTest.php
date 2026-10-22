<?php

use App\Models\Employee;
use App\Models\PlaidItem;
use App\Models\Setting;
use Database\Seeders\RoleSeeder;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\{Auth, Cache, Crypt, File, Http, Queue};
use Illuminate\Support\Str;

function disabledIntegrationResetRequestState(): void
{
    Auth::forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

function disabledIntegrationStartSession(Employee $employee, string $state): array
{
    $sessionPath = storage_path('framework/testing-sessions');
    File::ensureDirectoryExists($sessionPath);
    config(['session.driver'=>'file', 'session.files'=>$sessionPath]);
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    $session = app('session')->driver();
    $session->setId(Str::random(40));
    $session->start();
    app()->instance('session.store', $session);
    Auth::forgetGuards();
    if (! Auth::guard('web')->attempt(['email'=>$employee->email, 'password'=>'dummy-owner-password'])) {
        throw new RuntimeException('Dummy employee login failed.');
    }

    $session->regenerate();
    $session->put('podium_oauth_state', $state);
    $session->put('_token', Str::random(40));
    $session->save();
    $cookieName = config('session.cookie');
    $prefix = CookieValuePrefix::create($cookieName, app('encrypter')->getKey());
    $cookie = Crypt::encrypt($prefix.$session->getId(), false);
    $csrf = $session->token();
    Auth::forgetGuards();

    return [$cookie, $csrf];
}

function disabledIntegrationRequest($test, string $routeCase, ?string $cookie = null, ?string $csrf = null)
{
    $routes = [
        'podium-connect'=>['GET', '/podium/oauth/connect'],
        'podium-callback'=>['GET', '/podium/oauth/callback?code=dummy-code&state=dummy-one-use-state'],
        'plaid-connect'=>['GET', '/plaid/link/connect'],
        'plaid-exchange'=>['POST', '/plaid/link/exchange'],
    ];
    [$method, $uri] = $routes[$routeCase];
    $body = $routeCase === 'plaid-exchange' ? [
        '_token'=>$csrf,
        'public_token'=>'dummy-public-token',
        'institution_name'=>'Dummy Bank',
        'institution_id'=>'ins_dummy',
    ] : [];

    disabledIntegrationResetRequestState();
    $server = ['HTTP_ACCEPT'=>'application/json'];
    if ($csrf !== null) {
        $server['HTTP_X_CSRF_TOKEN'] = $csrf;
    }
    if ($method === 'POST') {
        $server['CONTENT_TYPE'] = 'application/json';
    }
    $cookies = $cookie === null ? [] : [config('session.cookie')=>$cookie];
    $response = $test->call(
        $method,
        $uri,
        $method === 'POST' ? [] : $body,
        $cookies,
        [],
        $server,
        $method === 'POST' ? json_encode($body, JSON_THROW_ON_ERROR) : null,
    );
    disabledIntegrationResetRequestState();

    return $response;
}

function disabledIntegrationAssertActiveResponse($response, string $routeCase): void
{
    if ($routeCase === 'plaid-connect' || $routeCase === 'plaid-exchange') {
        $response->assertOk();
        return;
    }

    $response->assertRedirect();
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = Employee::create([
        'name'=>'Boundary Owner', 'email'=>'boundary-owner@example.test', 'can_login'=>true,
        'active'=>true, 'password'=>bcrypt('dummy-owner-password'), 'job_functions'=>['manager'],
    ])->assignRole('super_admin');
});

it('denies a restored session after either login flag is cleared on each integration route', function (string $flag, string $routeCase) {
    Queue::fake();
    Http::preventStrayRequests();
    $providerCalls = 0;
    Http::fake(function (HttpRequest $request) use (&$providerCalls) {
        $providerCalls++;
        $url = $request->url();

        return match (true) {
            str_contains($url, '/link/token/create') => Http::response(['link_token'=>'dummy-link-token'], 200),
            str_contains($url, '/item/public_token/exchange') => Http::response([
                'item_id'=>'dummy-item', 'access_token'=>'dummy-access-token',
            ], 200),
            str_contains($url, '/oauth/token') => Http::response([
                'access_token'=>'dummy-podium-access', 'refresh_token'=>'dummy-podium-refresh',
            ], 200),
            str_contains($url, '/v4/locations') => Http::response([
                'data'=>[['uid'=>'dummy-location', 'organizationUid'=>'dummy-org']],
            ], 200),
            str_contains($url, '/v4/webhooks') => Http::response(['uid'=>'dummy-webhook'], 201),
            default => Http::response(['error'=>'unexpected dummy endpoint'], 500),
        };
    });

    Setting::put('plaid_client_id', 'dummy-plaid-client');
    Setting::put('plaid_secret', 'dummy-plaid-secret', sensitive: true);
    Setting::put('plaid_environment', 'sandbox');
    Setting::put('podium_client_id', 'dummy-podium-client');
    Setting::put('podium_client_secret', 'dummy-podium-secret', sensitive: true);

    // Route-level enabled control proves the same endpoint works before disable.
    [$controlCookie, $controlCsrf] = disabledIntegrationStartSession($this->owner, 'dummy-one-use-state');
    $control = disabledIntegrationRequest($this, $routeCase, $controlCookie, $controlCsrf);
    disabledIntegrationAssertActiveResponse($control, $routeCase);
    if ($routeCase === 'podium-connect') {
        expect($providerCalls)->toBe(0);
    } else {
        expect($providerCalls)->toBeGreaterThan(0);
    }

    PlaidItem::query()->delete();
    Setting::query()->whereIn('key', ['podium_refresh_token', 'podium_location_uid', 'podium_webhook_secret'])->delete();
    Cache::forget('podium_access_token');
    $providerCalls = 0;
    [$cookie, $csrf] = disabledIntegrationStartSession($this->owner, 'dummy-one-use-state');
    Employee::query()->whereKey($this->owner->id)->update([$flag=>false]);

    disabledIntegrationRequest($this, $routeCase, $cookie, $csrf)->assertForbidden();
    expect($providerCalls)->toBe(0)
        ->and(PlaidItem::query()->exists())->toBeFalse()
        ->and(Setting::get('podium_refresh_token'))->toBeNull()
        ->and(Setting::get('podium_location_uid'))->toBeNull();
})->with([
    'active cleared before Podium connect'=>['active', 'podium-connect'],
    'can_login cleared before Podium connect'=>['can_login', 'podium-connect'],
    'active cleared before Podium callback'=>['active', 'podium-callback'],
    'can_login cleared before Podium callback'=>['can_login', 'podium-callback'],
    'active cleared before Plaid connect'=>['active', 'plaid-connect'],
    'can_login cleared before Plaid connect'=>['can_login', 'plaid-connect'],
    'active cleared before Plaid exchange'=>['active', 'plaid-exchange'],
    'can_login cleared before Plaid exchange'=>['can_login', 'plaid-exchange'],
]);

it('denies unauthenticated requests to all four integration routes', function (string $routeCase) {
    $response = disabledIntegrationRequest($this, $routeCase);
    $response->assertUnauthorized();
})->with(['podium-connect', 'podium-callback', 'plaid-connect', 'plaid-exchange']);
