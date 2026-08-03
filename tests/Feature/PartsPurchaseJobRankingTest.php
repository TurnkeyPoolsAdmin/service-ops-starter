<?php

use App\Enums\BankMatchKind;
use App\Filament\Resources\BankTransactions\Tables\BankTransactionsTable;
use App\Models\BankTransaction;
use App\Models\BankTransactionMatch;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\Job;
use App\Models\PlaidItem;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;

/**
 * defaultPartJobOptions() (the "Job(s) the parts were for" picker's pre-search
 * list, Parts-purchase match kind) used to sort by created_at desc. It now
 * ranks by proximity to the charge amount instead — ranking only, never
 * exclusion, so every window/warehouse-eligible job is still offered, just
 * reordered. This test deliberately makes creation order and proximity order
 * DISAGREE (closest-amount job created oldest, furthest-amount job created
 * newest) so a regression back to created_at ordering would fail it.
 */
beforeEach(function () {
    test()->seed(EntitySeeder::class);

    test()->item = PlaidItem::create([
        'item_id' => 'item-parts-ranking', 'access_token' => 'access-test', 'institution_name' => 'Chase',
    ]);
});

function rankingJob(string $jobNumber, int $partCostCents, Carbon $createdAt, array $overrides = []): Job
{
    $customer = Customer::create(['first_name' => 'Test', 'phone_e164' => '+1818555'.substr(md5($jobNumber), 0, 4)]);

    return Job::create(array_merge([
        'job_number' => $jobNumber,
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Test Customer',
        'status' => 'scheduled',
        'part_cost_cents' => $partCostCents,
        'parts_ordered_at' => $createdAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ], $overrides));
}

function partsChargeTxn(array $overrides = []): BankTransaction
{
    return BankTransaction::create(array_merge([
        'plaid_item_id' => test()->item->id,
        'plaid_transaction_id' => 'txn-'.fake()->unique()->uuid(),
        'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking',
        'amount_cents' => 20000, // $200.00 charge — money OUT
        'date' => '2026-07-20',
        'name' => 'Some Parts Vendor',
        'pending' => false,
        'match_status' => BankTransaction::STATUS_UNMATCHED,
    ], $overrides));
}

function defaultPartJobOptionsFor(?string $warehouseValue, BankTransaction $record, array $alreadySelectedIds = []): array
{
    $method = new ReflectionMethod(BankTransactionsTable::class, 'defaultPartJobOptions');
    $method->setAccessible(true);

    return $method->invoke(null, $warehouseValue, $record, $alreadySelectedIds);
}

it('orders the default list by proximity to the charge amount, not creation date', function () {
    $txn = partsChargeTxn(); // $200.00 charge

    // Furthest from the charge ($100 delta), but created NEWEST.
    $far = rankingJob('LA-2026-0001', 30000, Carbon::parse('2026-07-19'));
    // Middle distance ($20 delta), created in between.
    $mid = rankingJob('LA-2026-0002', 18000, Carbon::parse('2026-07-15'));
    // Closest to the charge ($1 delta), but created OLDEST.
    $close = rankingJob('LA-2026-0003', 19900, Carbon::parse('2026-07-01'));

    $options = defaultPartJobOptionsFor(null, $txn);

    // Insertion order (mapWithKeys preserves it) must be closest -> furthest,
    // the exact reverse of created_at desc.
    expect(array_keys($options))->toBe([$close->id, $mid->id, $far->id]);
});

it('shows the amount delta in the default list labels but not in search results', function () {
    $txn = partsChargeTxn(); // $200.00 charge
    $close = rankingJob('LA-2026-0010', 19900, Carbon::parse('2026-07-10')); // $1 delta

    $defaultOptions = defaultPartJobOptionsFor(null, $txn);
    expect($defaultOptions[$close->id])->toContain('Δ $1.00');

    $searchMethod = new ReflectionMethod(BankTransactionsTable::class, 'jobOptions');
    $searchMethod->setAccessible(true);
    $searchOptions = $searchMethod->invoke(null, 'LA-2026-0010', null, $txn);

    expect($searchOptions[$close->id])->not->toContain('Δ');
});

/**
 * 2026-07-30: same real bug as the payment-line picker's
 * defaultPaymentLineOptions() — a fixed-full-charge Δ per line is nonsense
 * once 2+ jobs are already selected together.
 */
it('drops the delta from every label once two or more jobs are already selected', function () {
    $txn = partsChargeTxn(); // $200.00 charge
    $first = rankingJob('LA-2026-0030', 12000, Carbon::parse('2026-07-10'));
    $second = rankingJob('LA-2026-0031', 5000, Carbon::parse('2026-07-11'));
    $third = rankingJob('LA-2026-0032', 19900, Carbon::parse('2026-07-12'));

    $options = defaultPartJobOptionsFor(null, $txn, [$first->id, $second->id]);

    expect($options[$first->id])->not->toContain('Δ')
        ->and($options[$second->id])->not->toContain('Δ')
        ->and($options[$third->id])->not->toContain('Δ'); // whole list drops it, not just the selected ones
});

it('still excludes a job fully claimed by ANOTHER transaction, and one outside the date window - ranking never widens eligibility', function () {
    $txn = partsChargeTxn();

    $eligible = rankingJob('LA-2026-0020', 20000, Carbon::parse('2026-07-18'));
    $tooOld = rankingJob('LA-2026-0021', 20000, Carbon::parse('2026-01-01')); // outside the +/-45/+3 day window
    $alreadyLinked = rankingJob('LA-2026-0022', 20000, Carbon::parse('2026-07-18'));

    // Linked via a DIFFERENT transaction, not the one being reopened - the
    // real "someone else already fully claimed this job's part" case.
    // Linking it to $txn's OWN id would instead exercise the (correct,
    // 2026-07-31) $excluding behavior: reopening Match on a transaction
    // never counts that transaction's own prior claim against itself, same
    // as the payment-line picker's unmatchedPaymentLines($excluding).
    BankTransactionMatch::create([
        'bank_transaction_id' => partsChargeTxn()->id,
        'kind' => BankMatchKind::PartPurchase->value,
        'job_id' => $alreadyLinked->id,
        'source' => 'manual',
    ]);

    $options = defaultPartJobOptionsFor(null, $txn);

    expect($options)->toHaveKey($eligible->id)
        ->and($options)->not->toHaveKey($tooOld->id)
        ->and($options)->not->toHaveKey($alreadyLinked->id);
});

it('lets a name search reach a job outside the default window, while still surfacing in-window jobs', function () {
    $txn = partsChargeTxn();

    $inWindow = rankingJob('LA-2026-0030', 20000, Carbon::parse('2026-07-18'));
    $outsideWindowButNamed = rankingJob('LA-2026-0031', 20000, Carbon::parse('2026-01-01'));

    $searchMethod = new ReflectionMethod(BankTransactionsTable::class, 'jobOptions');
    $searchMethod->setAccessible(true);

    // Searching a substring that only the out-of-window job's number matches.
    $options = $searchMethod->invoke(null, '0031', null, $txn);
    expect($options)->toHaveKey($outsideWindowButNamed->id);

    // An empty/no-match search still surfaces the in-window job via the OR'd window clause.
    $windowOnly = $searchMethod->invoke(null, 'no-such-job-number', null, $txn);
    expect($windowOnly)->toHaveKey($inWindow->id)
        ->and($windowOnly)->not->toHaveKey($outsideWindowButNamed->id);
});
