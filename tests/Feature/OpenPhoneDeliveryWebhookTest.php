<?php

use App\Enums\MessageType;
use App\Enums\TemplateKey;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\ScheduledMessage;
use App\Models\Setting;
use App\Services\Messaging\WebhookSignatureVerifier;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    // A fixed weekday morning: Telegram posts nothing on a Sunday
    // (App\Support\AlertWindow), so a test asserting an alert must pin the day.
    Carbon::setTestNow(Carbon::parse('2026-08-03 09:00', 'America/Los_Angeles')); // Monday

    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    $this->secret = base64_encode('super-secret-key');
    Setting::put('openphone_webhook_secret', $this->secret, sensitive: true);
});

afterEach(fn () => Carbon::setTestNow());

function deliveryWebhook($test, array $payload): TestResponse
{
    $raw = json_encode($payload);
    $headers = ['Content-Type' => 'application/json'];
    $headers['openphone-signature'] = app(WebhookSignatureVerifier::class)
        ->sign($raw, $test->secret, '1700000000000');

    return $test->call('POST', '/webhooks/openphone', [], [], [], transformHeaders($headers), $raw);
}

function sentScheduledMessage(): ScheduledMessage
{
    $customer = Customer::create(['phone_e164' => '+18185551234', 'first_name' => 'Paul', 'last_name' => 'Test']);
    $entity = Entity::where('code', 'LA')->first();

    return ScheduledMessage::create([
        'customer_id' => $customer->id,
        'entity_id' => $entity->id,
        'template_key' => TemplateKey::JobComplete->value,
        'message_type' => MessageType::Completion->value,
        'to_phone_e164' => '+16265557227',
        'from_phone_e164' => '+18185550000',
        'rendered_body' => 'Job complete text body.',
        'status' => 'sent',
        'sent_at' => now(),
        'provider_message_id' => 'msg_abc123',
        'idempotency_key' => 'test:delivery:'.uniqid(),
    ]);
}

it('stamps delivered_at on a message.delivered webhook', function () {
    $message = sentScheduledMessage();

    deliveryWebhook($this, [
        'type' => 'message.delivered',
        'data' => ['object' => ['id' => 'msg_abc123', 'status' => 'delivered']],
    ])->assertOk();

    expect($message->refresh()->delivered_at)->not->toBeNull()
        ->and($message->delivery_failed_at)->toBeNull();
});

it('stamps delivery_failed_at and posts a Telegram alert on message.failed', function () {
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

    $message = sentScheduledMessage();

    deliveryWebhook($this, [
        'type' => 'message.failed',
        'data' => ['object' => [
            'id' => 'msg_abc123',
            'status' => 'undelivered',
            'error' => 'carrier violation: message too long',
        ]],
    ])->assertOk();

    $message->refresh();
    expect($message->delivery_failed_at)->not->toBeNull()
        ->and($message->delivery_error)->toBe('carrier violation: message too long');

    Http::assertSent(function ($req) {
        return str_contains($req->url(), '/bottest-token/sendMessage')
            && str_contains($req['text'], 'SMS delivery failed')
            && str_contains($req['text'], '626')
            && str_contains($req['text'], 'carrier violation');
    });
});

it('does not double-alert on a repeated message.failed webhook', function () {
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

    $message = sentScheduledMessage();
    $failedPayload = [
        'type' => 'message.failed',
        'data' => ['object' => ['id' => 'msg_abc123', 'status' => 'undelivered', 'error' => 'oops']],
    ];

    deliveryWebhook($this, $failedPayload)->assertOk();
    deliveryWebhook($this, $failedPayload)->assertOk();

    Http::assertSentCount(1);
});

it('ignores a delivery webhook for a message id we never sent', function () {
    deliveryWebhook($this, [
        'type' => 'message.delivered',
        'data' => ['object' => ['id' => 'msg_not_ours', 'status' => 'delivered']],
    ])->assertOk();

    expect(ScheduledMessage::whereNotNull('delivered_at')->count())->toBe(0);
});
