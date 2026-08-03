<?php

use App\Enums\PaymentMethod;
use App\Filament\Resources\BankTransactions\Tables\BankTransactionsTable;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\Job;
use App\Models\JobPayment;
use App\Models\PlaidItem;
use Database\Seeders\EntitySeeder;

/**
 * 2026-07-30 real bug: the "· Δ $X.XX" suffix on each payment-line option
 * is computed as "deposit minus THIS line's amount" — meaningful for a
 * single candidate, nonsense once 2+ lines are already selected (each
 * still shows its own delta against the FULL deposit, not the actual
 * shortfall). Real example: an $851.12 deposit, two selected lines
 * separately showing Δ $300.30 and Δ $516.63, neither of which relates to
 * the real $34.19 shortfall. Fixed by dropping the per-line Δ entirely
 * once 2+ lines are already selected — the real shortfall is the aggregate
 * depositTallyHelperText() shows below the field.
 */
beforeEach(function () {
    test()->seed(EntitySeeder::class);

    test()->item = PlaidItem::create([
        'item_id' => 'item-delta-label', 'access_token' => 'access-test', 'institution_name' => 'Chase',
    ]);
});

function zelleJobPayment(string $jobNumber, string $customerName, int $amountCents, string $collectedAt): JobPayment
{
    $customer = Customer::create(['first_name' => explode(' ', $customerName)[0], 'phone_e164' => '+1818555'.substr(md5($jobNumber), 0, 4)]);

    $job = Job::create([
        'job_number' => $jobNumber,
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customer->id,
        'customer_name_snapshot' => $customerName,
        'status' => 'scheduled',
        'quote_amount_cents' => $amountCents,
    ]);

    return JobPayment::create([
        'job_id' => $job->id,
        'amount_cents' => $amountCents,
        'method' => PaymentMethod::Zelle->value,
        'collected_at' => $collectedAt,
    ]);
}

function defaultPaymentLineOptionsWithSelection(BankTransaction $record, array $alreadySelectedIds): array
{
    $method = new ReflectionMethod(BankTransactionsTable::class, 'defaultPaymentLineOptions');
    $method->setAccessible(true);

    return $method->invoke(null, $record, $alreadySelectedIds);
}

it('shows the per-line delta when zero or one line is selected', function () {
    $deposit = BankTransaction::create([
        'plaid_item_id' => test()->item->id, 'plaid_transaction_id' => 'txn-delta-1', 'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking', 'amount_cents' => -85112, 'date' => '2026-07-21',
        'name' => 'Zelle payment', 'pending' => false, 'match_status' => BankTransaction::STATUS_UNMATCHED,
    ]);

    $line = zelleJobPayment('LA-2026-9101', 'Customer One', 40082, '2026-07-21 10:00:00');

    $options = defaultPaymentLineOptionsWithSelection($deposit, []); // nothing selected yet
    expect($options[$line->id])->toContain('Δ');

    $options = defaultPaymentLineOptionsWithSelection($deposit, [$line->id]); // this one line selected
    expect($options[$line->id])->toContain('Δ');
});

it('drops the per-line delta once two or more lines are already selected', function () {
    $deposit = BankTransaction::create([
        'plaid_item_id' => test()->item->id, 'plaid_transaction_id' => 'txn-delta-2', 'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking', 'amount_cents' => -85112, 'date' => '2026-07-21',
        'name' => 'Zelle payment', 'pending' => false, 'match_status' => BankTransaction::STATUS_UNMATCHED,
    ]);

    // Real example shape: two selected lines whose individual deltas
    // against the FULL deposit are nonsense (neither is the real $34.19
    // shortfall), plus a third still-unselected candidate.
    $first = zelleJobPayment('LA-2026-9102', 'Customer Two', 55082, '2026-07-21 09:00:00');
    $second = zelleJobPayment('LA-2026-9103', 'Customer Three', 33449, '2026-07-21 11:00:00');
    $third = zelleJobPayment('LA-2026-9104', 'Customer Four', 40000, '2026-07-21 12:00:00');

    $options = defaultPaymentLineOptionsWithSelection($deposit, [$first->id, $second->id]);

    expect($options[$first->id])->not->toContain('Δ')
        ->and($options[$second->id])->not->toContain('Δ')
        ->and($options[$third->id])->not->toContain('Δ'); // the whole list drops it, not just the selected ones
});
