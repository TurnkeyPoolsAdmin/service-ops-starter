<?php

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\Setting;
use App\Services\JobLifecycle;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    Setting::put('stripe_webhook_secret', 'whsec_test_secret', sensitive: true);

    $this->tech = Employee::create([
        'name' => 'Ramon Reyes', 'email' => 'r@test.local', 'can_login' => false, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['technician'],
    ]);
});

/** A job with a card payment link already created (pl_ id stored). */
function cardJob(array $overrides = []): Job
{
    $customer = Customer::firstOrCreate(['phone_e164' => '+18185551234'], ['first_name' => 'Cara']);

    return Job::create(array_merge([
        'job_number' => 'LA-2026-'.str_pad((string) (Job::count() + 1), 4, '0', STR_PAD_LEFT),
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Cara Delgado',
        'technician_id' => test()->tech->id,
        'status' => 'scheduled',
        'quote_amount_cents' => 30000,
        'stripe_invoice_id' => 'plink_ABC',
        'stripe_invoice_url' => 'https://buy.stripe.com/test_abc',
        'stripe_invoice_status' => 'open',
    ], $overrides));
}

/** POST a checkout.session.completed payload, signed like Stripe does. */
function postStripeWebhook(array $session, ?string $secret = 'whsec_test_secret'): \Illuminate\Testing\TestResponse
{
    $payload = json_encode([
        'type' => 'checkout.session.completed',
        'data' => ['object' => array_merge(['payment_status' => 'paid'], $session)],
    ]);

    $t = now()->timestamp;
    $sig = $secret ? hash_hmac('sha256', $t.'.'.$payload, $secret) : 'bad';

    return test()->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}",
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
}

it('records + auto-confirms a card payment when the link is paid', function () {
    $job = cardJob();

    postStripeWebhook([
        'payment_link' => 'plink_ABC',
        'payment_intent' => 'pi_123',
        'amount_total' => 30900, // quote + 3% surcharge the customer paid
    ])->assertOk();

    $job->refresh();
    expect($job->isPaid())->toBeTrue()
        ->and($job->amount_collected_cents)->toBe(30000) // quote recorded, not the surcharged total
        ->and($job->payment_method)->toBe(PaymentMethod::Stripe)
        ->and($job->stripe_paid_at)->not->toBeNull()
        ->and($job->stripe_payment_ref)->toBe('pi_123')
        ->and($job->payment_confirmed_at)->not->toBeNull()      // skips bank matching
        ->and($job->isPaymentReceiptConfirmed())->toBeTrue();
});

it('is idempotent across Stripe retries', function () {
    $job = cardJob();

    $session = ['payment_link' => 'plink_ABC', 'payment_intent' => 'pi_123', 'amount_total' => 30900];
    postStripeWebhook($session)->assertOk();
    postStripeWebhook($session)->assertOk(); // Stripe re-delivers

    expect($job->refresh()->payments()->count())->toBe(1)
        ->and($job->amount_collected_cents)->toBe(30000);
});

it('rejects an invalid signature', function () {
    $job = cardJob();

    postStripeWebhook(['payment_link' => 'plink_ABC'], secret: null)->assertStatus(400);

    expect($job->refresh()->isPaid())->toBeFalse();
});

it('acknowledges but ignores an unpaid session', function () {
    $job = cardJob();

    postStripeWebhook([
        'payment_link' => 'plink_ABC',
        'payment_status' => 'unpaid',
    ])->assertOk();

    expect($job->refresh()->isPaid())->toBeFalse();
});

it('resolves the job by the payment link id', function () {
    $job = cardJob(['stripe_invoice_id' => 'plink_XYZ']);

    postStripeWebhook(['payment_link' => 'plink_XYZ', 'payment_intent' => 'pi_9'])->assertOk();

    expect($job->refresh()->isPaid())->toBeTrue();
});

it('acknowledges a paid session that matches no job', function () {
    postStripeWebhook(['payment_link' => 'plink_UNKNOWN'])->assertOk();

    expect(Job::whereNotNull('stripe_paid_at')->count())->toBe(0);
});

it('leaves an already fully-paid job untouched but stamps it handled', function () {
    $job = cardJob();
    // Pay it in full by another method first.
    app(JobLifecycle::class)->recordPayment($job, 30000, PaymentMethod::Zelle, null, null, test()->tech);

    postStripeWebhook(['payment_link' => 'plink_ABC', 'payment_intent' => 'pi_late'])->assertOk();

    $job->refresh();
    expect($job->payments()->count())->toBe(1)           // no duplicate Stripe line
        ->and($job->stripe_paid_at)->not->toBeNull();     // still marked handled
});
