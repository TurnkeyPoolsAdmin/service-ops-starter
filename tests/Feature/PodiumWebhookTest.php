<?php

use App\Models\Customer;
use App\Models\PodiumMessage;
use App\Models\Setting;
use App\Services\Podium\PodiumMessageService;
use App\Services\Podium\PodiumSignatureVerifier;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->podiumSecret = 'podium-signing-secret';
    Setting::put('podium_webhook_secret', $this->podiumSecret, sensitive: true);
});

function postPodium($test, array $payload, ?string $signature = null, string $timestamp = '1700000000000'): TestResponse
{
    $raw = json_encode($payload);
    $signature ??= app(PodiumSignatureVerifier::class)->sign($raw, $timestamp, $test->podiumSecret);

    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PODIUM_SIGNATURE' => $signature,
        'HTTP_PODIUM_TIMESTAMP' => $timestamp,
    ];

    return $test->call('POST', '/webhooks/podium', [], [], [], $server, $raw);
}

function podiumMessage(string $eventType, string $identifier, string $body, string $type = 'phone'): array
{
    return [
        'metadata' => ['eventType' => $eventType, 'version' => '2021.04.01'],
        'data' => [
            'body' => $body,
            'createdAt' => '2026-06-24T21:36:52.684589Z',
            'conversation' => [
                'uid' => 'conv-1',
                'channel' => ['type' => $type, 'identifier' => $identifier],
            ],
            'uid' => null,
        ],
    ];
}

it('rejects an invalid signature with 401', function () {
    postPodium($this, podiumMessage('message.received', '+18185551234', 'hi'), signature: 'nope')->assertStatus(401);
    expect(PodiumMessage::count())->toBe(0);
});

it('stores an inbound Podium message and matches the customer', function () {
    $customer = Customer::create(['phone_e164' => '+18185551234', 'first_name' => 'Al']);

    postPodium($this, podiumMessage('message.received', '+18185551234', 'Yes Tuesday works!'))->assertOk();

    $msg = PodiumMessage::first();
    expect($msg)->not->toBeNull()
        ->and($msg->direction)->toBe('inbound')
        ->and($msg->channel_type)->toBe('phone')
        ->and($msg->contact_phone_e164)->toBe('+18185551234')
        ->and($msg->customer_id)->toBe($customer->id)
        ->and($msg->body)->toBe('Yes Tuesday works!');
});

it('marks message.sent and message.failed as outbound (from sales)', function () {
    postPodium($this, podiumMessage('message.sent', '+18185551234', 'Can you do Tuesday?'))->assertOk();
    postPodium($this, podiumMessage('message.failed', '+18185551234', 'undelivered'))->assertOk();

    $directions = PodiumMessage::pluck('direction')->all();
    expect($directions)->toBe(['outbound', 'outbound']);
});

it('is idempotent on webhook redelivery of the same event', function () {
    $payload = podiumMessage('message.received', '+18185551234', 'first');
    postPodium($this, $payload)->assertOk();
    postPodium($this, $payload)->assertOk();

    expect(PodiumMessage::count())->toBe(1);
});

it('dedupes real events on the message uid even when content differs', function () {
    $payload = function (string $body) {
        return [
            'metadata' => ['eventType' => 'message.received', 'version' => '2021.04.01'],
            'data' => [
                'uid' => '019efb1a-d01d-779b-adf9-742c9b2fae1b', // stable message id
                'body' => $body,
                'createdAt' => '2026-06-24T19:28:19Z',
                'conversation' => ['uid' => 'conv-x', 'channel' => ['type' => 'phone', 'identifier' => '+12095551979']],
            ],
        ];
    };

    postPodium($this, $payload(''))->assertOk();      // e.g. attachment, empty body
    postPodium($this, $payload('now with text'))->assertOk();

    expect(PodiumMessage::count())->toBe(1)
        ->and(PodiumMessage::first()->provider_message_id)->toBe('019efb1a-d01d-779b-adf9-742c9b2fae1b');
});

it('acks and ignores non-message events', function () {
    postPodium($this, [
        'metadata' => ['eventType' => 'call.completed', 'version' => '2021.04.01'],
        'data' => ['uid' => 'call-1'],
    ])->assertOk()->assertJson(['ignored' => 'call.completed']);

    expect(PodiumMessage::count())->toBe(0);
});

it('does not surface email-channel messages in the phone lookup', function () {
    postPodium($this, podiumMessage('message.received', 'ana@example.com', 'emailed you', type: 'email'))->assertOk();

    expect(PodiumMessage::count())->toBe(1)
        ->and(PodiumMessage::first()->contact_phone_e164)->toBe('')
        ->and(app(PodiumMessageService::class)->recentFor('+18185551234'))->toHaveCount(0);
});
