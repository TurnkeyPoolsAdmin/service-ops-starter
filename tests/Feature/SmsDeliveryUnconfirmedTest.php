<?php

use App\Enums\MessageType;
use App\Enums\TemplateKey;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\ScheduledMessage;
use App\Models\Setting;
use App\Services\Messaging\TemplateRenderer;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Mid-morning Pacific — the watchdog is silent outside App\Support\AlertWindow.
    Carbon::setTestNow(Carbon::parse('2026-07-13 09:00', 'America/Los_Angeles'));
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Setting::put('outgoing_provider', 'openphone');
});

afterEach(fn () => Carbon::setTestNow());

function sweepMessage(array $overrides = []): ScheduledMessage
{
    $customer = Customer::create(['phone_e164' => '+13105556553', 'first_name' => 'Louis', 'last_name' => 'Test']);
    $entity = Entity::where('code', 'LA')->first();

    return ScheduledMessage::create(array_merge([
        'customer_id' => $customer->id,
        'entity_id' => $entity->id,
        'template_key' => TemplateKey::JobComplete->value,
        'message_type' => MessageType::Completion->value,
        'to_phone_e164' => '+13105556553',
        'from_phone_e164' => '+18185550000',
        'provider' => 'openphone',
        'rendered_body' => 'Job complete body.',
        'status' => 'sent',
        'sent_at' => now()->subMinutes(20),
        'provider_message_id' => 'AC'.uniqid(),
        'idempotency_key' => 'test:sweep:'.uniqid(),
    ], $overrides));
}

it('alerts on a sent message that was never confirmed delivered, once', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    $message = sweepMessage();

    $this->artisan('alerts:sms-delivery-unconfirmed')->assertSuccessful();

    expect($message->refresh()->delivery_unconfirmed_alerted_at)->not->toBeNull();
    Http::assertSent(fn ($req) => str_contains($req['text'], 'not confirmed delivered')
        && str_contains($req['text'], '310'));

    // Second run must not re-alert.
    $this->artisan('alerts:sms-delivery-unconfirmed')->assertSuccessful();
    Http::assertSentCount(1);
});

it('does not alert while still inside the grace period', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true], 200)]);
    sweepMessage(['sent_at' => now()->subMinutes(2)]);

    $this->artisan('alerts:sms-delivery-unconfirmed')->assertSuccessful();

    Http::assertNothingSent();
});

it('does not alert once the delivery webhook confirmed the message', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true], 200)]);
    sweepMessage(['delivered_at' => now()->subMinutes(19)]);

    $this->artisan('alerts:sms-delivery-unconfirmed')->assertSuccessful();

    Http::assertNothingSent();
});

it('does not alert for a provider that does not emit delivery receipts', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true], 200)]);
    sweepMessage(['provider' => 'podium']);

    $this->artisan('alerts:sms-delivery-unconfirmed')->assertSuccessful();

    Http::assertNothingSent();
});

it('stamps without alerting when telegram alerts are disabled', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true], 200)]);
    Setting::put('telegram_alerts_enabled', '0');
    $message = sweepMessage();

    $this->artisan('alerts:sms-delivery-unconfirmed')->assertSuccessful();

    expect($message->refresh()->delivery_unconfirmed_alerted_at)->not->toBeNull();
    Http::assertNothingSent();
});

it('normalizes smart quotes and dashes out of a rendered body', function () {
    $renderer = app(TemplateRenderer::class);
    $body = $renderer->render("Hi {name}, you\u{2019}d like this \u{2014} really.", ['name' => 'Al']);

    expect($body)->toBe("Hi Al, you'd like this - really.")
        ->and($body)->not->toContain("\u{2019}")
        ->and($body)->not->toContain("\u{2014}");
});
