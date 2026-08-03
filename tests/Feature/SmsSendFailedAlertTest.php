<?php

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\TemplateKey;
use App\Jobs\SendSmsJob;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\ScheduledMessage;
use App\Models\Setting;
use App\Services\Messaging\Providers\MessageProvider;
use App\Services\Messaging\Providers\MessageProviderFactory;
use App\Services\Messaging\Providers\ProviderResult;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // A fixed weekday morning: Telegram posts nothing on a Sunday
    // (App\Support\AlertWindow), so a test asserting an alert must pin the day.
    Carbon::setTestNow(Carbon::parse('2026-08-03 09:00', 'America/Los_Angeles')); // Monday

    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
});

afterEach(fn () => Carbon::setTestNow());

/** Force every send through a provider that always fails with the given reason. */
function failingProvider(string $reason = 'Could not obtain a Podium access token.'): void
{
    $provider = new class($reason) implements MessageProvider
    {
        public function __construct(private string $reason) {}

        public function send(string $to, ?string $from, string $body): ProviderResult
        {
            return ProviderResult::failed('auth', $this->reason);
        }
    };

    $factory = Mockery::mock(MessageProviderFactory::class);
    $factory->shouldReceive('make')->andReturn($provider);
    app()->instance(MessageProviderFactory::class, $factory);
}

function queuedMessage(?string $provider = null, int $attemptCount = 2): ScheduledMessage
{
    $customer = Customer::create(['phone_e164' => '+18185551234', 'first_name' => 'Ada', 'last_name' => 'Tester']);
    $entity = Entity::where('code', 'LA')->first();

    return ScheduledMessage::create([
        'customer_id' => $customer->id,
        'entity_id' => $entity->id,
        'template_key' => TemplateKey::WelcomePodiumHandoff->value,
        'message_type' => MessageType::Welcome->value,
        'to_phone_e164' => '+15555550100',
        'from_phone_e164' => '+18185550000',
        'rendered_body' => 'Welcome text body.',
        'status' => MessageStatus::Queued->value,
        'provider' => $provider,
        // One below max_attempts, so the next attempt is the terminal one.
        'attempt_count' => $attemptCount,
        'idempotency_key' => 'test:sendfail:'.uniqid(),
    ]);
}

it('posts a Telegram alert when a send exhausts its retries', function () {
    failingProvider();
    $message = queuedMessage(provider: 'podium');

    SendSmsJob::dispatchSync($message->id);

    expect($message->refresh()->status)->toBe(MessageStatus::Failed);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/sendMessage')
            && str_contains($request['text'], 'SMS failed to send')
            && str_contains($request['text'], 'Podium')
            && str_contains($request['text'], 'Could not obtain a Podium access token.');
    });
});

it('names Quo as the provider when the row has no override', function () {
    failingProvider('Unauthorized');
    Setting::put('outgoing_provider', 'openphone');
    $message = queuedMessage();

    SendSmsJob::dispatchSync($message->id);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
        && str_contains($request['text'], 'Quo / OpenPhone'));
});

it('does not alert on a transient failure that will still be retried', function () {
    failingProvider();
    $message = queuedMessage(attemptCount: 0);

    SendSmsJob::dispatchSync($message->id);

    // Back to queued for another go — no alert yet.
    expect($message->refresh()->status)->toBe(MessageStatus::Queued);
    Http::assertNothingSent();
});

it('stays quiet when telegram alerts are switched off', function () {
    failingProvider();
    Setting::put('telegram_alerts_enabled', '0');
    $message = queuedMessage(provider: 'podium');

    SendSmsJob::dispatchSync($message->id);

    expect($message->refresh()->status)->toBe(MessageStatus::Failed);
    Http::assertNothingSent();
});

it('still marks the row failed when the alert itself blows up', function () {
    failingProvider();
    Http::fake(['*/sendMessage' => Http::response('nope', 500)]);
    $message = queuedMessage(provider: 'podium');

    SendSmsJob::dispatchSync($message->id);

    expect($message->refresh()->status)->toBe(MessageStatus::Failed);
});
