<?php

use App\Enums\MessageStatus;
use App\Enums\TemplateKey;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\InboundMessage;
use App\Models\Setting;
use App\Services\JobLifecycle;
use App\Services\Messaging\OutboxService;
use App\Services\Messaging\WebhookSignatureVerifier;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    $this->secret = base64_encode('super-secret-key');
    Setting::put('openphone_webhook_secret', $this->secret, sensitive: true);
});

function postWebhook($test, array $payload, bool $sign = true): TestResponse
{
    $raw = json_encode($payload);
    $headers = ['Content-Type' => 'application/json'];

    if ($sign) {
        $headers['openphone-signature'] = app(WebhookSignatureVerifier::class)
            ->sign($raw, $test->secret, '1700000000000');
    } else {
        $headers['openphone-signature'] = 'hmac;1;1700000000000;not-a-real-signature';
    }

    return $test->call('POST', '/webhooks/openphone', [], [], [], transformHeaders($headers), $raw);
}

function transformHeaders(array $headers): array
{
    $server = [];
    foreach ($headers as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }

    return $server;
}

function inboundPayload(string $from, string $text): array
{
    return [
        'type' => 'message.received',
        'data' => ['object' => [
            'id' => 'm_1', 'from' => $from, 'to' => ['+18185550000'],
            'text' => $text, 'direction' => 'incoming', 'createdAt' => '2026-06-17T12:00:00Z',
        ]],
    ];
}

it('captures the Quo conversation link for the matched customer from a webhook', function () {
    $customer = Customer::create(['phone_e164' => '+18185551234', 'first_name' => 'Susan']);

    $payload = inboundPayload('+18185551234', 'Hi!');
    $payload['data']['object']['phoneNumberId'] = 'PNK3QamaPL';
    $payload['data']['object']['conversationId'] = 'CN59e86035159042c090ac53185379c6eb';

    postWebhook($this, $payload)->assertOk();

    expect($customer->refresh()->quo_conversation_url)
        ->toBe('https://my.quo.com/inbox/PNK3QamaPL/c/CN59e86035159042c090ac53185379c6eb');
});

it('rejects an invalid signature with 401', function () {
    $res = postWebhook($this, inboundPayload('+18185551234', 'hello'), sign: false);
    $res->assertStatus(401);
    expect(InboundMessage::count())->toBe(0);
});

it('stores an inbound message and matches the customer', function () {
    $customer = Customer::create(['phone_e164' => '+18185551234', 'first_name' => 'Al']);

    postWebhook($this, inboundPayload('818-555-1234', 'Thanks!'))->assertOk();

    $inbound = InboundMessage::first();
    expect($inbound)->not->toBeNull()
        ->and($inbound->customer_id)->toBe($customer->id)
        ->and($inbound->detected_intent->value)->toBe('other');
    expect($customer->refresh()->last_inbound_at)->not->toBeNull();
});

it('opts the customer out on inbound STOP and cancels queued messages', function () {
    $actor = Employee::create(['name' => 'Op', 'email' => 'o@t.local', 'can_login' => true, 'active' => true, 'password' => bcrypt('x'), 'job_functions' => ['office']]);
    $actor->assignRole('super_admin');

    $job = app(JobLifecycle::class)->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Al', 'quote_amount_cents' => 50000,
    ], $actor);

    // Queue a welcome so STOP has a queued row to cancel.
    app(OutboxService::class)
        ->queueForJob($job, TemplateKey::WelcomeDispatchIntro, null, $actor->id, 'openphone');
    expect($job->scheduledMessages()->where('status', 'queued')->count())->toBe(1);

    postWebhook($this, inboundPayload('+18185551234', 'STOP'))->assertOk();

    $customer = Customer::where('phone_e164', '+18185551234')->first();
    expect($customer->do_not_text)->toBeTrue()
        ->and($customer->opt_out_source->value)->toBe('inbound_sms')
        ->and($job->scheduledMessages()->where('status', MessageStatus::Queued->value)->count())->toBe(0);
});

it('honors opt-out phrases beyond exact STOP', function () {
    Customer::create(['phone_e164' => '+18185551234', 'first_name' => 'Al']);

    postWebhook($this, inboundPayload('+18185551234', 'please REMOVE ME from this list'))->assertOk();

    expect(Customer::where('phone_e164', '+18185551234')->first()->do_not_text)->toBeTrue();
});
