<?php

use App\Enums\PaymentMethod;
use App\Filament\Resources\BankTransactions\Tables\BankTransactionsTable;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\Job;
use App\Models\PlaidItem;
use Database\Seeders\EntitySeeder;

/**
 * The "Payment line(s) this deposit covers" picker showed the payment method
 * buried inside the amount bit ("$150.00 Zelle"). It now sits right next to
 * the customer name so a human can eyeball "does this method make sense for
 * this deposit" without opening anything. Covers both label builders that
 * back the same field (paymentLineLabel for everything, cardLineLabel for
 * Stripe deposits) and confirms the method survives the Δ-suppression rule
 * from earlier this session (Δ hides once 2+ lines are selected; the method
 * must not hide with it).
 */
function invokeLabel(string $method, ...$args)
{
    $ref = new ReflectionMethod(BankTransactionsTable::class, $method);
    $ref->setAccessible(true);

    return $ref->invoke(null, ...$args);
}

beforeEach(function () {
    test()->seed(EntitySeeder::class);

    $this->item = PlaidItem::create([
        'item_id' => 'item-methodlabel-1',
        'access_token' => 'access-methodlabel',
        'institution_name' => 'Chase',
    ]);

    $this->customer = Customer::firstOrCreate(['phone_e164' => '+18185558888'], ['first_name' => 'Test']);
    $this->entityId = Entity::where('code', 'LA')->value('id');
});

function labelJob(array $overrides = []): Job
{
    return Job::create(array_merge([
        'job_number' => 'LA-2026-0042',
        'entity_id' => test()->entityId,
        'customer_id' => test()->customer->id,
        'customer_name_snapshot' => 'Sonia Lopez',
        'status' => 'completed',
    ], $overrides));
}

it('shows the method right after the customer name, not buried in the amount', function () {
    $job = labelJob();
    $payment = $job->payments()->create([
        'amount_cents' => 55105, 'method' => PaymentMethod::Zelle->value, 'collected_at' => '2026-07-22',
    ]);

    $label = invokeLabel('paymentLineLabel', $payment->fresh(), null, null);

    expect($label)->toContain('Sonia Lopez · Zelle')
        ->and($label)->toStartWith('LA-2026-0042 — Sonia Lopez · Zelle —');
});

it('keeps showing the method once 2+ lines are selected and Δ is suppressed', function () {
    $job = labelJob();
    $payment = $job->payments()->create([
        'amount_cents' => 55105, 'method' => PaymentMethod::Zelle->value, 'collected_at' => '2026-07-22',
    ]);

    // depositCents === null is exactly what the 2+-selected path passes -
    // this is the real signature multi-select hits per defaultPaymentLineOptions().
    $label = invokeLabel('paymentLineLabel', $payment->fresh(), null, null);

    expect($label)->toContain('· Zelle')
        ->and($label)->not->toContain('· Δ');
});

it('still shows the method alongside Δ when only one line is selected', function () {
    $job = labelJob();
    $payment = $job->payments()->create([
        'amount_cents' => 55105, 'method' => PaymentMethod::Zelle->value, 'collected_at' => '2026-07-22',
    ]);

    $label = invokeLabel('paymentLineLabel', $payment->fresh(), 55000, null);

    expect($label)->toContain('· Zelle')
        ->and($label)->toContain('· Δ');
});

it('card label carries the method too, for the same field', function () {
    $job = labelJob();
    $payment = $job->payments()->create([
        'amount_cents' => 55105, 'method' => PaymentMethod::Stripe->value, 'collected_at' => '2026-07-22',
    ]);

    $label = invokeLabel('cardLineLabel', $payment->fresh(), null);

    expect($label)->toContain('Sonia Lopez · Stripe/CC');
});

it('the search-results path (not just the default list) also carries the method', function () {
    $txn = BankTransaction::create([
        'plaid_item_id' => test()->item->id,
        'plaid_transaction_id' => 'txn-'.fake()->unique()->uuid(),
        'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking',
        'amount_cents' => -55105,
        'date' => '2026-07-22',
        'name' => 'Zelle payment from SONIA LOPEZ',
        'pending' => false,
        'match_status' => BankTransaction::STATUS_UNMATCHED,
    ]);
    $job = labelJob();
    $payment = $job->payments()->create([
        'amount_cents' => 55105, 'method' => PaymentMethod::Zelle->value, 'collected_at' => '2026-07-22',
    ]);

    $results = invokeLabel('searchPaymentLineOptions', 'Sonia', $txn);

    expect($results[$payment->id])->toContain('· Zelle');
});

it('degrades gracefully when the job is deleted (no name, method still shows)', function () {
    $job = labelJob();
    $payment = $job->payments()->create([
        'amount_cents' => 55105, 'method' => PaymentMethod::Venmo->value, 'collected_at' => '2026-07-22',
    ]);
    $payment->refresh();
    $job->delete();
    $payment->unsetRelation('job');

    $label = invokeLabel('paymentLineLabel', $payment, null, null);

    expect($label)->toContain('Venmo');
});
