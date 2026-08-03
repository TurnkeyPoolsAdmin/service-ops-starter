<?php

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\JobPayment;
use App\Models\Setting;
use App\Models\StripePayout;
use App\Models\StripePayoutLine;
use App\Services\Stripe\StripeClient;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;

/**
 * Covers the payout.paid webhook branch (StripeWebhookController) and
 * StripePayoutResolver — resolved / partial / resolution_failed, and that a
 * failed Stripe call never blocks the webhook response (Stripe must always
 * get 2xx once the signature is valid, per the existing checkout.session.
 * completed handler's own convention). StripeClient is faked (no real
 * network) since resolving a payout requires a second live Stripe call this
 * app has no test credentials for.
 */
beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    Setting::put('stripe_webhook_secret', 'whsec_test_secret', sensitive: true);

    $this->tech = Employee::create([
        'name' => 'Ramon Reyes', 'email' => 'r2@test.local', 'can_login' => false, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['technician'],
    ]);

    $customer = Customer::firstOrCreate(['phone_e164' => '+18185559999'], ['first_name' => 'Priya']);
    $this->job = Job::create([
        'job_number' => 'LA-2026-9001',
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Priya Nair',
        'technician_id' => $this->tech->id,
        'status' => 'scheduled',
        'quote_amount_cents' => 50000,
    ]);

    $this->jobPayment = JobPayment::create([
        'job_id' => $this->job->id,
        'amount_cents' => 50000,
        'method' => PaymentMethod::Stripe->value,
        'reference' => 'pi_123',
        'collected_at' => now(),
    ]);
});

/**
 * Swap in a StripeClient whose listPayoutBalanceTransactions() returns/throws
 * exactly what the test wants. A partial mock (not a full stub) — everything
 * else, notably verifyWebhook(), still runs for real against the
 * stripe_webhook_secret Setting so signature verification stays genuinely
 * exercised.
 */
function fakeStripeClient(?array $transactions = null, ?Throwable $throws = null): void
{
    $mock = Mockery::mock(StripeClient::class)->makePartial();
    $expectation = $mock->shouldReceive('listPayoutBalanceTransactions');
    $throws !== null ? $expectation->andThrow($throws) : $expectation->andReturn($transactions ?? []);

    app()->instance(StripeClient::class, $mock);
}

/** POST a payout.paid payload, signed like Stripe does. */
function postPayoutWebhook(array $payout, ?string $secret = 'whsec_test_secret'): \Illuminate\Testing\TestResponse
{
    $payload = json_encode([
        'type' => 'payout.paid',
        'data' => ['object' => array_merge(['status' => 'paid', 'currency' => 'usd'], $payout)],
    ]);

    $t = now()->timestamp;
    $sig = $secret ? hash_hmac('sha256', $t.'.'.$payload, $secret) : 'bad';

    return test()->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}",
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
}

function chargeBalanceTransaction(string $txnId, string $paymentIntentId, int $gross, int $fee): array
{
    return [
        'id' => $txnId,
        'type' => 'charge',
        'amount' => $gross,
        'fee' => $fee,
        'net' => $gross - $fee,
        'source' => [
            'id' => 'ch_'.$txnId,
            'payment_intent' => ['id' => $paymentIntentId],
        ],
    ];
}

it('resolves a payout whose charge matches a known job_payment', function () {
    fakeStripeClient([
        chargeBalanceTransaction('txn_1', 'pi_123', 51500, 1524),
    ]);

    $arrival = now()->addDay();
    postPayoutWebhook(['id' => 'po_1', 'amount' => 49976, 'arrival_date' => $arrival->timestamp])->assertOk();

    $payout = StripePayout::where('stripe_payout_id', 'po_1')->firstOrFail();
    expect($payout->resolution_status)->toBe(StripePayout::STATUS_RESOLVED)
        ->and($payout->amount_cents)->toBe(49976)
        ->and($payout->arrival_date)->toBe($arrival->utc()->toDateString())
        ->and($payout->resolved_at)->not->toBeNull();

    $line = StripePayoutLine::where('stripe_payout_id', $payout->id)->firstOrFail();
    expect($line->job_payment_id)->toBe($this->jobPayment->id)
        ->and($line->gross_cents)->toBe(51500)
        ->and($line->fee_cents)->toBe(1524)
        ->and($line->net_cents)->toBe(49976)
        ->and($line->type)->toBe('charge');
});

it('marks the payout partial when a charge cannot be matched to any job_payment', function () {
    fakeStripeClient([
        chargeBalanceTransaction('txn_1', 'pi_123', 51500, 1524),
        chargeBalanceTransaction('txn_2', 'pi_UNKNOWN', 20600, 900),
    ]);

    postPayoutWebhook(['id' => 'po_2', 'amount' => 69076, 'arrival_date' => now()->timestamp])->assertOk();

    $payout = StripePayout::where('stripe_payout_id', 'po_2')->firstOrFail();
    expect($payout->resolution_status)->toBe(StripePayout::STATUS_PARTIAL);

    $unresolved = StripePayoutLine::where('stripe_payout_id', $payout->id)
        ->where('stripe_balance_transaction_id', 'txn_2')->firstOrFail();
    expect($unresolved->job_payment_id)->toBeNull()
        ->and($unresolved->isUnresolvedCharge())->toBeTrue();
});

it('records resolution_failed without blocking the webhook response when Stripe errors', function () {
    fakeStripeClient(throws: new RuntimeException('Stripe GET failed: connection timed out'));

    postPayoutWebhook(['id' => 'po_3', 'amount' => 10000, 'arrival_date' => now()->timestamp])
        ->assertOk()
        ->assertJsonPath('resolution_status', StripePayout::STATUS_RESOLUTION_FAILED);

    $payout = StripePayout::where('stripe_payout_id', 'po_3')->firstOrFail();
    expect($payout->resolution_status)->toBe(StripePayout::STATUS_RESOLUTION_FAILED)
        ->and($payout->resolution_attempts)->toBe(1)
        ->and($payout->resolution_error)->toContain('connection timed out')
        ->and($payout->resolved_at)->toBeNull();
});

it('does not duplicate lines or reprocess an already-resolved payout', function () {
    fakeStripeClient([chargeBalanceTransaction('txn_1', 'pi_123', 51500, 1524)]);
    postPayoutWebhook(['id' => 'po_4', 'amount' => 49976, 'arrival_date' => now()->timestamp])->assertOk();

    $payout = StripePayout::where('stripe_payout_id', 'po_4')->firstOrFail();
    expect(StripePayoutLine::where('stripe_payout_id', $payout->id)->count())->toBe(1);

    // Stripe redelivers the same webhook.
    postPayoutWebhook(['id' => 'po_4', 'amount' => 49976, 'arrival_date' => now()->timestamp])->assertOk();

    expect(StripePayout::where('stripe_payout_id', 'po_4')->count())->toBe(1)
        ->and(StripePayoutLine::where('stripe_payout_id', $payout->id)->count())->toBe(1);
});

it('acknowledges a payout.paid event with no payout id', function () {
    postPayoutWebhook(['amount' => 100])->assertOk();

    expect(StripePayout::count())->toBe(0);
});

it('rejects a payout.paid event with an invalid signature', function () {
    postPayoutWebhook(['id' => 'po_5', 'amount' => 100], secret: null)->assertStatus(400);

    expect(StripePayout::count())->toBe(0);
});
