<?php

use App\Enums\PaymentMethod;
use App\Filament\Resources\BankTransactions\Tables\BankTransactionsTable;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\Job;
use App\Models\JobPayment;
use App\Models\PlaidItem;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;

/**
 * Regression for a real production bug found against deposit 947
 * (2026-07-29, $4,760.04, 7/21/26): defaultPaymentLineOptions() surfaced
 * only 1 of 7 genuinely unmatched Stripe job_payments in a -7/+1 day window.
 * Root cause: a same-Pacific-day match short-circuited the ENTIRE fallback
 * query (`$sameDay->isNotEmpty() ? $sameDay : ...`), and even that fallback
 * (±3 days) was too narrow — the real candidates span 7/15 through 7/22.
 *
 * This test reconstructs that exact scenario with the real customer names/
 * amounts/dates from the bug report (not synthetic placeholders) so a
 * regression back to the old two-tier query fails it visibly.
 */
beforeEach(function () {
    test()->seed(EntitySeeder::class);

    test()->item = PlaidItem::create([
        'item_id' => 'item-stripe-window', 'access_token' => 'access-test', 'institution_name' => 'Chase',
    ]);
});

function stripeJobPayment(string $jobNumber, string $entityCode, string $customerName, int $revenueCents, string $collectedAt): JobPayment
{
    $customer = Customer::create(['first_name' => explode(' ', $customerName)[0], 'phone_e164' => '+1818555'.substr(md5($jobNumber), 0, 4)]);

    $job = Job::create([
        'job_number' => $jobNumber,
        'entity_id' => Entity::where('code', $entityCode)->value('id'),
        'customer_id' => $customer->id,
        'customer_name_snapshot' => $customerName,
        'status' => 'scheduled',
        'quote_amount_cents' => $revenueCents,
    ]);

    return JobPayment::create([
        'job_id' => $job->id,
        'amount_cents' => $revenueCents,
        'method' => PaymentMethod::Stripe->value,
        'reference' => 'pi_'.$jobNumber,
        'collected_at' => Carbon::parse($collectedAt, 'America/Los_Angeles')->utc(),
    ]);
}

function defaultPaymentLineOptionsFor(BankTransaction $record, array $alreadySelectedIds = []): array
{
    $method = new ReflectionMethod(BankTransactionsTable::class, 'defaultPaymentLineOptions');
    $method->setAccessible(true);

    return $method->invoke(null, $record, $alreadySelectedIds);
}

it('surfaces all real candidates in a wide window, not just a same-day match (deposit 947 regression)', function () {
    // The 7 real unmatched candidates from the bug report.
    $karen = stripeJobPayment('LA-2026-0066', 'LA', 'Karen Belmonte', 51500, '2026-07-15 18:38:11');
    $steven = stripeJobPayment('LA-2026-0065', 'LA', 'Steven Hang', 56650, '2026-07-16 20:35:40');
    $trinity = stripeJobPayment('LA-2026-0077', 'LA', 'Trinity M.', 72100, '2026-07-17 18:15:33');
    $abouzar = stripeJobPayment('DelMar-2026-0002', 'DelMar', 'Abouzar Moradian', 51500, '2026-07-17 19:49:53');
    $george = stripeJobPayment('LA-2026-0075', 'LA', 'George Price', 88147, '2026-07-17 20:45:34');
    $louis = stripeJobPayment('LA-2026-0073', 'LA', 'Louis Bomotti', 33475, '2026-07-17 22:37:11');
    $gilbert = stripeJobPayment('LA-2026-0098', 'LA', 'Gilbert Camacho', 78795, '2026-07-22 00:18:48');

    $deposit = BankTransaction::create([
        'plaid_item_id' => test()->item->id,
        'plaid_transaction_id' => 'txn-deposit-947',
        'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking',
        'amount_cents' => -476004, // $4,760.04 deposit
        'date' => '2026-07-21',
        'name' => 'STRIPE TRANSFER',
        'pending' => false,
        'match_status' => BankTransaction::STATUS_UNMATCHED,
    ]);

    $options = defaultPaymentLineOptionsFor($deposit);

    // The old bug: only Gilbert (the lone same-day match) would show here.
    foreach ([$karen, $steven, $trinity, $abouzar, $george, $louis, $gilbert] as $line) {
        expect($options)->toHaveKey($line->id);
    }
    expect($options)->toHaveCount(7);
});

it('ranks the default list by proximity between expected net and the deposit, closest first', function () {
    $deposit = BankTransaction::create([
        'plaid_item_id' => test()->item->id,
        'plaid_transaction_id' => 'txn-deposit-rank',
        'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking',
        'amount_cents' => -50000, // $500.00 deposit
        'date' => '2026-07-21',
        'name' => 'STRIPE TRANSFER',
        'pending' => false,
        'match_status' => BankTransaction::STATUS_UNMATCHED,
    ]);

    // Furthest from $500 net, collected closest to the deposit date.
    $far = stripeJobPayment('LA-2026-0001', 'LA', 'Far Customer', 100000, '2026-07-21 12:00:00');
    // Closest to $500 net, collected furthest from the deposit date (within the window).
    $close = stripeJobPayment('LA-2026-0002', 'LA', 'Close Customer', 50030, '2026-07-15 12:00:00');

    $options = defaultPaymentLineOptionsFor($deposit);

    // Insertion order must be closest -> furthest, the reverse of collected_at desc.
    expect(array_keys($options))->toBe([$close->id, $far->id]);
});

it('excludes a candidate outside the -7/+1 day window', function () {
    $deposit = BankTransaction::create([
        'plaid_item_id' => test()->item->id,
        'plaid_transaction_id' => 'txn-deposit-window',
        'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking',
        'amount_cents' => -50000,
        'date' => '2026-07-21',
        'name' => 'STRIPE TRANSFER',
        'pending' => false,
        'match_status' => BankTransaction::STATUS_UNMATCHED,
    ]);

    $inWindow = stripeJobPayment('LA-2026-0010', 'LA', 'In Window', 50000, '2026-07-14 12:00:00'); // exactly -7 days
    $tooOld = stripeJobPayment('LA-2026-0011', 'LA', 'Too Old', 50000, '2026-07-13 12:00:00'); // -8 days
    $tooFar = stripeJobPayment('LA-2026-0012', 'LA', 'Too Far', 50000, '2026-07-23 12:00:00'); // +2 days

    $options = defaultPaymentLineOptionsFor($deposit);

    expect($options)->toHaveKey($inWindow->id)
        ->and($options)->not->toHaveKey($tooOld->id)
        ->and($options)->not->toHaveKey($tooFar->id);
});
