<?php

use App\Models\Customer;
use App\Models\InboundMessage;
use App\Models\Setting;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    $this->token = 'rc-verify-token';
    Setting::put('ringcentral_webhook_token', $this->token, sensitive: true);
});

function rcServerHeaders(array $headers): array
{
    $server = [];
    foreach ($headers as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }

    return $server;
}

function postRingCentral($test, array $payload, ?string $token = null): TestResponse
{
    $raw = json_encode($payload);
    $headers = [
        'Content-Type' => 'application/json',
        'Verification-Token' => $token ?? $test->token,
    ];

    return $test->call('POST', '/webhooks/ringcentral', [], [], [], rcServerHeaders($headers), $raw);
}

function rcInboundPayload(string $from, string $text, int $id = 101): array
{
    return [
        'uuid' => 'evt-1',
        'event' => '/restapi/v1.0/account/~/extension/~/message-store/instant?type=SMS',
        'subscriptionId' => 'sub-1',
        'body' => [
            'id' => $id,
            'type' => 'SMS',
            'direction' => 'Inbound',
            'from' => ['phoneNumber' => $from],
            'to' => [['phoneNumber' => '+18185550000']],
            'subject' => $text,
            'creationTime' => '2026-06-17T12:00:00Z',
        ],
    ];
}

it('echoes the validation token on the subscription registration handshake', function () {
    $res = $this->call('POST', '/webhooks/ringcentral', [], [], [],
        rcServerHeaders(['Validation-Token' => 'abc-123']), '');

    $res->assertOk();
    expect($res->headers->get('Validation-Token'))->toBe('abc-123');
});

it('rejects a notification with a bad verification token', function () {
    $res = postRingCentral($this, rcInboundPayload('+18185551234', 'hello'), token: 'wrong-token');

    $res->assertStatus(401);
    expect(InboundMessage::count())->toBe(0);
});

it('stores an inbound SMS and matches the customer', function () {
    $customer = Customer::create(['phone_e164' => '+18185551234', 'first_name' => 'Al']);

    postRingCentral($this, rcInboundPayload('818-555-1234', 'Thanks!'))->assertOk();

    $inbound = InboundMessage::first();
    expect($inbound)->not->toBeNull()
        ->and($inbound->customer_id)->toBe($customer->id)
        ->and($inbound->body)->toBe('Thanks!')
        ->and($inbound->detected_intent->value)->toBe('other');
    expect($customer->refresh()->last_inbound_at)->not->toBeNull();
});

it('opts the customer out on an inbound STOP', function () {
    Customer::create(['phone_e164' => '+18185551234', 'first_name' => 'Al']);

    postRingCentral($this, rcInboundPayload('+18185551234', 'STOP'))->assertOk();

    expect(Customer::where('phone_e164', '+18185551234')->first()->do_not_text)->toBeTrue();
});

it('ignores duplicate re-deliveries of the same message id', function () {
    $payload = rcInboundPayload('+18185551234', 'Hi', id: 555);

    postRingCentral($this, $payload)->assertOk();
    postRingCentral($this, $payload)->assertOk();

    expect(InboundMessage::count())->toBe(1);
});

it('acks but ignores outbound (our own) message echoes', function () {
    $payload = rcInboundPayload('+18185550000', 'our reply');
    $payload['body']['direction'] = 'Outbound';

    postRingCentral($this, $payload)->assertOk();

    expect(InboundMessage::count())->toBe(0);
});
