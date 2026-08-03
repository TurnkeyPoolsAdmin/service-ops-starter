<?php

use App\Enums\ContactPref;
use App\Enums\MessageType;
use App\Enums\TemplateKey;
use App\Enums\TimePreference;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\PodiumMessage;
use App\Models\ScheduledMessage;
use App\Models\Setting;
use App\Services\Intake\SalesIntakeService;
use App\Services\JobLifecycle;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Podium\PodiumThreadReader;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

const SALES_SECRET = 'a-long-shared-secret-value';

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    // Fixed weekday mid-morning so the dispatch-intro VARIANT is deterministic
    // (after-hours / Sunday / holiday pick different template keys).
    Carbon::setTestNow(Carbon::parse('2026-07-13 10:00', 'America/Los_Angeles')); // Monday

    Setting::put('sales_intake_secret', SALES_SECRET, sensitive: true);
});

afterEach(fn () => Carbon::setTestNow());

/** The contract payload, with overrides merged in at the top level. */
function salesPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'external_source' => SalesIntakeService::SOURCE,
        'external_ref' => '1234',
        'is_test' => false,
        'booked_at' => '2026-07-13T17:00:00Z',
        'entity_code' => 'LA',
        'customer' => [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone_e164' => '+14155551234',
            'service_address' => '123 Main St, Los Angeles, CA 90001',
            'city' => 'Los Angeles',
        ],
        'vehicle' => [
            'year' => 2021,
            'make' => 'Honda',
            'model' => 'Civic',
            'raw' => '2021 Honda Civic',
        ],
        'glass_position' => 'Windshield',
        'part_number' => 'FW05339',
        'part_available' => 'yes',
        'quote_amount_cents' => 45000,
        'is_insurance' => false,
        'insurance_carrier' => null,
        'requested_day' => '2026-07-14',
        'requested_time_preference' => 'morning',
        'requested_time' => null,
        'requested_daytime_raw' => 'Tomorrow morning',
        'contact_pref' => 'sms',
        'notes' => 'Park in the driveway.',
        'salesperson' => [
            'name' => 'Alex B',
            'phone' => '+14155559999',
        ],
    ], $overrides);
}

/** POST the payload, signed the way the contract says the sender signs it. */
function postSalesIntake(
    array $payload,
    ?string $secret = SALES_SECRET,
    ?int $timestamp = null,
    bool $headers = true,
): TestResponse {
    $body = json_encode($payload);
    $ts = $timestamp ?? now()->timestamp;

    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($headers) {
        $server['HTTP_X_SALES_TIMESTAMP'] = (string) $ts;
        $server['HTTP_X_SALES_SIGNATURE'] = $secret === null
            ? 'deadbeef'
            : hash_hmac('sha256', $ts.'.'.$body, $secret);
    }

    return test()->call('POST', '/webhooks/sales-intake', [], [], [], $server, $body);
}

// ---------------------------------------------------------------- auth

it('accepts a correctly signed push', function () {
    postSalesIntake(salesPayload())->assertStatus(201);

    expect(Job::count())->toBe(1);
});

it('rejects a bad signature with 401 and creates nothing', function () {
    postSalesIntake(salesPayload(), secret: 'the-wrong-secret')->assertStatus(401);

    expect(Job::count())->toBe(0);
});

it('rejects a timestamp outside the replay window with 401', function () {
    postSalesIntake(salesPayload(), timestamp: now()->timestamp - 301)->assertStatus(401);

    // Just inside the window is still fine.
    postSalesIntake(salesPayload(), timestamp: now()->timestamp - 299)->assertStatus(201);
});

it('rejects missing signature headers with 400', function () {
    postSalesIntake(salesPayload(), headers: false)->assertStatus(400);

    expect(Job::count())->toBe(0);
});

it('refuses to run in production without a configured secret', function () {
    $this->app['env'] = 'production';
    Setting::put('sales_intake_secret', null, sensitive: true);

    postSalesIntake(salesPayload())->assertStatus(503);

    expect(Job::count())->toBe(0);
});

// ------------------------------------------------------- creation + mapping

it('creates the job with every contract field mapped', function () {
    $response = postSalesIntake(salesPayload())->assertStatus(201);

    $job = Job::firstOrFail();

    $response->assertJson([
        'status' => 'created',
        'job_id' => $job->id,
        'job_number' => $job->job_number,
    ]);

    expect($job->entity->code)->toBe('LA')
        ->and($job->status->value)->toBe('pending_schedule')
        ->and($job->external_source)->toBe(SalesIntakeService::SOURCE)
        ->and($job->external_ref)->toBe('1234')
        ->and($job->customer_name_snapshot)->toBe('Jane Doe')
        ->and($job->customer_phone_snapshot)->toBe('+14155551234')
        ->and($job->service_address)->toBe('123 Main St, Los Angeles, CA 90001')
        ->and($job->city)->toBe('Los Angeles')
        ->and($job->vehicle_year)->toBe('2021')
        ->and($job->vehicle_make)->toBe('Honda')
        ->and($job->vehicle_model)->toBe('Civic')
        ->and($job->vehicle_raw)->toBe('2021 Honda Civic')
        ->and($job->glass_position)->toBe('Windshield')
        ->and($job->part_number)->toBe('FW05339')
        ->and($job->part_available)->toBeTrue()
        // Money stays integer cents end to end - no decimal ever touches it.
        ->and($job->quote_amount_cents)->toBe(45000)
        ->and($job->is_insurance)->toBeFalse()
        ->and($job->requested_day)->toBe('2026-07-14')
        ->and($job->requested_time_preference)->toBe(TimePreference::Morning)
        ->and($job->requested_daytime_raw)->toBe('Tomorrow morning')
        ->and($job->notes)->toBe('Park in the driveway.')
        ->and($job->is_test)->toBeFalse()
        // The rep is a sales-dashboard user, so the snapshot carries them.
        ->and($job->booking_salesperson_id)->toBeNull()
        ->and($job->booking_salesperson_name)->toBe('Alex B')
        ->and($job->booking_salesperson_phone)->toBe('+14155559999')
        ->and($job->bookingSalespersonName())->toBe('Alex B')
        // Created by the dedicated system actor, not a real dispatcher.
        ->and($job->createdBy->email)->toBe(SalesIntakeService::SYSTEM_ACTOR_EMAIL)
        ->and($job->createdBy->name)->toBe('Sales Dashboard')
        ->and($job->customer->contact_pref)->toBe(ContactPref::Text);
});

it('logs the sales-dashboard origin on the job timeline', function () {
    postSalesIntake(salesPayload())->assertStatus(201);

    $job = Job::firstOrFail();

    $log = Activity::where('subject_type', $job->getMorphClass())
        ->where('subject_id', $job->id)
        ->get();

    expect($log->pluck('description')->all())
        ->toContain('Lead created from sales dashboard (lead #1234)')
        ->and($log->firstWhere('event', 'lead_created_sales_intake')->causer->name)
        ->toBe('Sales Dashboard');
});

it('merges the snapshot rep into the {salesperson} text variable', function () {
    postSalesIntake(salesPayload())->assertStatus(201);

    // No employees row exists for "Alex B", so only the snapshot can answer.
    $vars = app(TemplateRenderer::class)->varsForJob(Job::firstOrFail());

    expect($vars['salesperson'])->toBe('Alex B');
});

it('composes the requested day/time when the sender did not send one', function () {
    postSalesIntake(salesPayload(['requested_daytime_raw' => null]))->assertStatus(201);

    expect(Job::firstOrFail()->requested_daytime_raw)->toBe('Tuesday, July 14 Morning');
});

it('carries an "after 10am" request through with its hour', function () {
    postSalesIntake(salesPayload([
        'requested_time_preference' => 'after',
        'requested_after_time' => '10:00',
        'requested_daytime_raw' => null,
    ]))->assertStatus(201);

    $job = Job::firstOrFail();

    expect($job->requested_time_preference)->toBe(TimePreference::After)
        ->and($job->requested_after_time)->toBe('10:00')
        ->and($job->requestedTimeLabel())->toBe('After 10am')
        ->and($job->requested_daytime_raw)->toBe('Tuesday, July 14 After 10am');
});

it('drops an hour sent against a preference that has no use for one', function () {
    postSalesIntake(salesPayload([
        'requested_time_preference' => 'morning',
        'requested_after_time' => '10:00',
    ]))->assertStatus(201);

    expect(Job::firstOrFail()->requested_after_time)->toBeNull();
});

it('422s an after hour that is not a clock time', function () {
    postSalesIntake(salesPayload([
        'requested_time_preference' => 'after',
        'requested_after_time' => 'ten-ish',
    ]))->assertStatus(422)
        ->assertJsonPath('errors.requested_after_time.0', fn ($m) => is_string($m));
});

it('routes a lead to the new PHX entity', function () {
    postSalesIntake(salesPayload(['entity_code' => 'PHX']))->assertStatus(201);

    $job = Job::firstOrFail();

    expect($job->entity->code)->toBe('PHX')
        ->and($job->entity->name)->toBe('Auto Glass of Scottsdale')
        ->and($job->job_number)->toStartWith('PHX-')
        // Scottsdale is its OWN tech pool, never merged with LA/SFV.
        ->and($job->entity->techPoolEntityIds())->toBe([$job->entity_id]);
});

it('maps part availability and insurance', function () {
    postSalesIntake(salesPayload([
        'external_ref' => '9001',
        'part_available' => 'na',
        'is_insurance' => true,
        'insurance_carrier' => 'Geico',
    ]))->assertStatus(201);

    $job = Job::firstOrFail();

    expect($job->part_available)->toBeNull()
        ->and($job->is_insurance)->toBeTrue()
        ->and($job->insurance_carrier)->toBe('Geico')
        // Insurance on a windshield always implies calibration.
        ->and($job->needs_calibration)->toBeTrue();
});

// ---------------------------------------------------------------- idempotency

it('answers 200 duplicate for a lead it already has', function () {
    postSalesIntake(salesPayload())->assertStatus(201);
    $job = Job::firstOrFail();

    postSalesIntake(salesPayload())
        ->assertStatus(200)
        ->assertJson([
            'status' => 'duplicate',
            'job_id' => $job->id,
            'job_number' => $job->job_number,
        ]);

    expect(Job::count())->toBe(1);
});

it('does not resend texts on a duplicate push', function () {
    Setting::put('sales_auto_intake_enabled', '1');

    postSalesIntake(salesPayload())->assertStatus(201);
    $before = ScheduledMessage::count();

    postSalesIntake(salesPayload())->assertStatus(200);

    expect(ScheduledMessage::count())->toBe($before);
});

it('lets two different sales leads through', function () {
    postSalesIntake(salesPayload(['external_ref' => '1']))->assertStatus(201);
    postSalesIntake(salesPayload(['external_ref' => '2']))->assertStatus(201);

    expect(Job::count())->toBe(2);
});

// ---------------------------------------------------------------- validation

it('422s an unknown entity code', function () {
    postSalesIntake(salesPayload(['entity_code' => 'NOPE']))
        ->assertStatus(422)
        ->assertJsonPath('errors.entity_code.0', 'Unknown or inactive entity code [NOPE].');

    expect(Job::count())->toBe(0);
});

it('422s a deactivated entity code', function () {
    Entity::where('code', 'DelMar')->update(['active' => false]);

    postSalesIntake(salesPayload(['entity_code' => 'DelMar']))->assertStatus(422);
});

it('422s a missing required field with the offending key', function () {
    $payload = salesPayload();
    unset($payload['customer']['phone_e164']);

    postSalesIntake($payload)
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['customer.phone_e164']]);

    expect(Job::count())->toBe(0);
});

it('422s a non-E.164 phone and an unknown glass position', function () {
    postSalesIntake(salesPayload(['customer' => ['phone_e164' => '415-555-1234']]))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['customer.phone_e164']]);

    postSalesIntake(salesPayload(['glass_position' => 'Sunroof Left Maybe']))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['glass_position']]);
});

// ------------------------------------------------------------- auto-intake

it('sends nothing when auto-intake is off', function () {
    // The default. The job simply waits on the Send-welcome screen.
    postSalesIntake(salesPayload())->assertStatus(201);

    expect(ScheduledMessage::count())->toBe(0)
        ->and(Job::firstOrFail()->status->value)->toBe('pending_schedule');
});

it('sends the Podium handoff plus the dispatch intro when a conversation exists', function () {
    Setting::put('sales_auto_intake_enabled', '1');

    PodiumMessage::create([
        'direction' => 'outbound',
        'contact_phone_e164' => '+14155551234',
        'body' => 'Hi Jane, this is Alex from Auto Glass of LA.',
        'occurred_at' => now()->subHour(),
        'provider_message_id' => 'pod-1',
    ]);

    postSalesIntake(salesPayload())->assertStatus(201);

    $sent = ScheduledMessage::where('message_type', MessageType::Welcome->value)->get();

    expect($sent)->toHaveCount(2)
        ->and($sent->pluck('template_key')->map(fn ($k) => $k->value)->all())
        ->toContain(TemplateKey::WelcomePodiumHandoff->value)
        ->toContain(TemplateKey::WelcomeDispatchIntro->value)
        // The handoff goes out from Podium, the intro from Quo.
        ->and($sent->firstWhere('template_key', TemplateKey::WelcomePodiumHandoff)->provider)->toBe('podium')
        ->and($sent->firstWhere('template_key', TemplateKey::WelcomeDispatchIntro)->provider)->toBe('openphone');
});

it('sends only the dispatch intro when the customer is not in Podium', function () {
    Setting::put('sales_auto_intake_enabled', '1');

    postSalesIntake(salesPayload())->assertStatus(201);

    $sent = ScheduledMessage::where('message_type', MessageType::Welcome->value)->get();

    expect($sent)->toHaveCount(1)
        ->and($sent->first()->template_key)->toBe(TemplateKey::WelcomeDispatchIntro)
        ->and($sent->first()->provider)->toBe('openphone');
});

it('never texts a test lead, even with auto-intake on', function () {
    Setting::put('sales_auto_intake_enabled', '1');

    postSalesIntake(salesPayload(['is_test' => true]))->assertStatus(201);

    expect(Job::firstOrFail()->is_test)->toBeTrue()
        ->and(ScheduledMessage::count())->toBe(0);
});

it('still creates the job when the Podium check blows up', function () {
    Setting::put('sales_auto_intake_enabled', '1');

    $this->mock(PodiumThreadReader::class)
        ->shouldReceive('latestFor')->andThrow(new RuntimeException('podium down'));

    postSalesIntake(salesPayload())->assertStatus(201);

    // Falls back to the OFF behaviour: nothing sent, job waits for a human.
    expect(Job::count())->toBe(1)
        ->and(ScheduledMessage::count())->toBe(0);
});

// ---------------------------------------------------------------- telegram

it('posts a New job booked alert linking to send-welcome while auto-intake is off', function () {
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');

    postSalesIntake(salesPayload())->assertStatus(201);

    $job = Job::firstOrFail();

    Http::assertSent(function ($request) use ($job) {
        return str_contains($request->url(), '/sendMessage')
            && str_contains($request['text'], 'New job booked')
            && str_contains($request['text'], 'send-welcome?job='.$job->id)
            && str_contains($request['text'], 'Alex B');
    });
});

it('links the alert to the job view once auto-intake is on', function () {
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Setting::put('sales_auto_intake_enabled', '1');

    postSalesIntake(salesPayload())->assertStatus(201);

    $job = Job::firstOrFail();

    Http::assertSent(function ($request) use ($job) {
        return str_contains($request->url(), '/sendMessage')
            && str_contains($request['text'], '/admin/jobs/'.$job->id)
            && ! str_contains($request['text'], 'send-welcome');
    });
});

it('stays quiet when Telegram alerts are switched off or the job is a test', function () {
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Setting::put('telegram_alerts_enabled', '0');

    postSalesIntake(salesPayload())->assertStatus(201);
    Http::assertNothingSent();

    Setting::put('telegram_alerts_enabled', '1');
    postSalesIntake(salesPayload(['external_ref' => '777', 'is_test' => true]))->assertStatus(201);
    Http::assertNothingSent();
});

it('does not alert on a manually intaken job', function () {
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');

    $actor = Employee::create([
        'name' => 'Nico', 'email' => 'nico@test.local', 'active' => true,
        'can_login' => true, 'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);

    app(JobLifecycle::class)->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185550001',
        'first_name' => 'Manual',
        'quote_amount_cents' => 30000,
    ], $actor);

    Http::assertNothingSent();
});
