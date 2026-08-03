<?php

use App\Enums\PartsSupplier;
use App\Enums\PaymentMethod;
use App\Filament\Resources\BankTransactions\Tables\BankTransactionsTable;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\Job;
use App\Models\PlaidItem;
use App\Models\VendorDocument;
use Database\Seeders\EntitySeeder;

/**
 * All three Match-modal pickers (payment lines, parts-purchase jobs, vendor
 * documents) took only a name/number text search — dead for the legacy-import
 * case, which has no customer name a dispatcher recognizes, and useless
 * whenever the only thing known is a dollar figure. Amount search is
 * ADDITIVE: it unions in candidates within tolerance of a typed dollar
 * amount, on top of whatever the text search already found, and a search
 * that isn't shaped like an amount (a job number, a name) never triggers it.
 *
 * These call the picker methods directly via reflection — they're private
 * static helpers on a plain PHP class, not Livewire state, so this is a true
 * unit test of the search logic without the overhead of driving the modal.
 */
function invokePicker(string $method, ...$args)
{
    $ref = new ReflectionMethod(BankTransactionsTable::class, $method);
    $ref->setAccessible(true);

    return $ref->invoke(null, ...$args);
}

beforeEach(function () {
    test()->seed(EntitySeeder::class);

    $this->item = PlaidItem::create([
        'item_id' => 'item-amtsearch-1',
        'access_token' => 'access-amtsearch',
        'institution_name' => 'Chase',
    ]);

    $this->customer = Customer::firstOrCreate(['phone_e164' => '+18185559999'], ['first_name' => 'Test']);
    $this->entityId = Entity::where('code', 'LA')->value('id');
});

// ---- parseAmountSearch() ----

it('parses plain and dollar-prefixed amounts, rejects text', function (string $input, ?int $expectedCents) {
    expect(invokePicker('parseAmountSearch', $input))->toBe($expectedCents);
})->with([
    ['330', 33000],
    ['$330', 33000],
    ['330.50', 33050],
    ['1,234.56', 123456],
    ['LA-2026-0330', null],   // a real job number - must never parse as $330
    ['Alyssa Lazo', null],
    ['0', null],              // below the $1 floor
    ['0.50', null],
]);

// ---- Payment lines picker ----

function amtBankTxn(array $overrides = []): BankTransaction
{
    return BankTransaction::create(array_merge([
        'plaid_item_id' => test()->item->id,
        'plaid_transaction_id' => 'txn-'.fake()->unique()->uuid(),
        'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking',
        'amount_cents' => -33000,
        'date' => '2026-07-07',
        'name' => 'Zelle payment from ALYSSA LAZO',
        'pending' => false,
        'match_status' => BankTransaction::STATUS_UNMATCHED,
    ], $overrides));
}

function amtJob(array $overrides = []): Job
{
    return Job::create(array_merge([
        'job_number' => 'LA-2026-'.str_pad((string) (Job::count() + 1), 4, '0', STR_PAD_LEFT),
        'entity_id' => test()->entityId,
        'customer_id' => test()->customer->id,
        'customer_name_snapshot' => 'Alyssa Lazo',
        'status' => 'completed',
    ], $overrides));
}

it('payment-line picker finds a line by amount when the name is unrecognizable (the legacy case)', function () {
    $txn = amtBankTxn();
    // A customer name a dispatcher has never heard of - the exact scenario
    // that made this feature necessary. Text search for "330" against
    // job_number/customer_name_snapshot would find nothing.
    $job = amtJob(['customer_name_snapshot' => 'Nobody Recognizable']);
    $payment = $job->payments()->create([
        'amount_cents' => 33000, 'method' => PaymentMethod::Zelle->value, 'collected_at' => '2026-07-07',
    ]);

    $results = invokePicker('searchPaymentLineOptions', '330', $txn);

    expect($results)->toHaveKey($payment->id);
});

it('payment-line picker still finds a line by job number/customer name text with no amount typed', function () {
    $txn = amtBankTxn();
    $job = amtJob(['job_number' => 'LA-2026-9999']);
    $payment = $job->payments()->create([
        'amount_cents' => 99999, 'method' => PaymentMethod::Zelle->value, 'collected_at' => '2026-07-07',
    ]);

    $results = invokePicker('searchPaymentLineOptions', '9999', $txn);

    expect($results)->toHaveKey($payment->id);
});

it('payment-line picker respects the ± tolerance and excludes an out-of-range amount', function () {
    $txn = amtBankTxn();
    $job = amtJob();
    $tooFarOff = $job->payments()->create([
        'amount_cents' => 33600, // $6.00 away - outside ±$5
        'method' => PaymentMethod::Zelle->value, 'collected_at' => '2026-07-07',
    ]);

    $results = invokePicker('searchPaymentLineOptions', '330', $txn);

    expect($results)->not->toHaveKey($tooFarOff->id);
});

it('amount search finds a line well within the lookback cap, regardless of the default list\'s tight window', function () {
    config(['glass.bank_matching.search_lookback_days' => 365]);

    // 200 days back - the default list's -7/+1 day window would never show
    // this, and this is exactly the case amount search exists for.
    $txn = amtBankTxn(['date' => '2026-07-30']);
    $job = amtJob(['customer_name_snapshot' => 'Nobody Recognizable']);
    $oldLine = $job->payments()->create([
        'amount_cents' => 33000, 'method' => PaymentMethod::Zelle->value, 'collected_at' => '2026-01-11',
    ]);

    $results = invokePicker('searchPaymentLineOptions', '330', $txn);

    expect($results)->toHaveKey($oldLine->id);
});

it('amount search does not fetch a line older than the configured lookback cap', function () {
    config(['glass.bank_matching.search_lookback_days' => 365]);

    // 400 days back - past the cap - the performance backstop this test exists for.
    $txn = amtBankTxn(['date' => '2026-07-30']);
    $job = amtJob(['customer_name_snapshot' => 'Nobody Recognizable']);
    $tooOldLine = $job->payments()->create([
        'amount_cents' => 33000, 'method' => PaymentMethod::Zelle->value, 'collected_at' => '2025-06-25',
    ]);

    $results = invokePicker('searchPaymentLineOptions', '330', $txn);

    expect($results)->not->toHaveKey($tooOldLine->id);
});

// ---- Parts-purchase job picker ----

it('parts-purchase picker finds a job by its net part cost', function () {
    $txn = amtBankTxn(['amount_cents' => 25000, 'name' => 'MYGRANT GLASS CO #018']);
    $job = amtJob(['job_number' => 'LA-2026-8888', 'part_cost_cents' => 25000]);

    $results = invokePicker('jobOptions', '250', null, $txn);

    expect($results)->toHaveKey($job->id);
});

it('parts-purchase picker matches NET cost (part cost minus tech-paid), not the gross part cost', function () {
    $txn = amtBankTxn(['amount_cents' => 15000, 'name' => 'MYGRANT GLASS CO #018']);
    // Gross $250, tech fronted $100 -> net $150. A search for "150" must find
    // this job on its NET, exactly like the default amount-ranked list does.
    $job = amtJob(['job_number' => 'LA-2026-7777', 'part_cost_cents' => 25000, 'tech_parts_cost_cents' => 10000]);

    $results = invokePicker('jobOptions', '150', null, $txn);

    expect($results)->toHaveKey($job->id);
});

// ---- Vendor document picker ----

it('vendor-document picker finds a document by its effective amount', function () {
    $txn = amtBankTxn(['amount_cents' => 84800, 'name' => 'MYGRANT GLASS CO #018']);
    $doc = VendorDocument::create([
        'supplier' => PartsSupplier::Mygrant->value,
        'document_number' => 'I05061199-0',
        'type' => 'invoice',
        'document_date' => '2026-06-01',
        'cut_date' => '2026-06-01',
        'settle_date' => '2026-06-03',
        'net_amount_cents' => 84800,
        'source' => 'pdf',
    ]);

    $results = invokePicker('searchVendorDocumentOptions', '848', $txn, PartsSupplier::Mygrant->value);

    expect($results)->toHaveKey($doc->id);
});

it('vendor-document picker matches the EFFECTIVE amount (net + accepted difference), not raw net_amount_cents', function () {
    $txn = amtBankTxn(['amount_cents' => 90000, 'name' => 'MYGRANT GLASS CO #018']);
    $doc = VendorDocument::create([
        'supplier' => PartsSupplier::Mygrant->value,
        'document_number' => 'I05061200-0',
        'type' => 'invoice',
        'document_date' => '2026-06-01',
        'cut_date' => '2026-06-01',
        'settle_date' => '2026-06-03',
        'net_amount_cents' => 85000,
        'accepted_difference_cents' => 5000,
        'source' => 'pdf',
    ]);

    $results = invokePicker('searchVendorDocumentOptions', '900', $txn, PartsSupplier::Mygrant->value);

    expect($results)->toHaveKey($doc->id);
});
