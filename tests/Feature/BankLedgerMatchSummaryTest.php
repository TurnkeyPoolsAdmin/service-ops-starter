<?php

use App\Enums\BankMatchKind;
use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\LegacyManualPayment;
use App\Models\PlaidItem;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;

/**
 * The ledger's "Matched to" badge only ever links to a Job or a tech payout.
 * Everything else — a legacy-import payment, a misc category, a dividend, a
 * vendor document, or a row carrying several matches — rendered as a bare word
 * with no way to see what was being proposed before confirming it. These cover
 * the summary line that now spells it out.
 */
beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->item = PlaidItem::create([
        'item_id' => 'item-summary-1',
        'access_token' => 'access-summary',
        'institution_name' => 'Chase',
    ]);

    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'summaryadmin@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->admin->assignRole('super_admin');
});

function summaryJob(string $jobNumber): Job
{
    $customer = Customer::firstOrCreate(['phone_e164' => '+18185550000'], ['first_name' => 'Test']);

    return Job::create([
        'job_number' => $jobNumber,
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Alan Fairview',
        'status' => 'completed',
    ]);
}

function summaryTxn(array $overrides = []): BankTransaction
{
    return BankTransaction::create(array_merge([
        'plaid_item_id' => test()->item->id,
        'plaid_transaction_id' => 'txn-'.fake()->unique()->uuid(),
        'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking',
        'amount_cents' => -25000,
        'date' => '2026-07-01',
        'name' => 'Zelle payment from ALYSSA LAZO',
        'pending' => false,
        'match_status' => BankTransaction::STATUS_SUGGESTED,
    ], $overrides));
}

it('names the customer on a legacy-import suggestion', function () {
    $txn = summaryTxn();

    $payment = LegacyManualPayment::create([
        'source_row' => 83,
        'payment_date' => '2026-07-01',
        'customer_name' => 'Alyssa Lazo',
        'paid_cents' => 25000,
        'status' => LegacyManualPayment::STATUS_MATCHED,
    ]);

    $txn->matches()->create([
        'kind' => BankMatchKind::JobPayment->value,
        'legacy_manual_payment_id' => $payment->id,
        'source' => 'legacy_import',
    ]);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class, ['activeTab' => 'needs_review'])
        ->assertCanSeeTableRecords([$txn])
        // The whole point: the row says WHO, not just "Suggested".
        ->assertSee('Alyssa Lazo');
});

it('summarises a transaction carrying several matches instead of going blank', function () {
    $txn = summaryTxn(['amount_cents' => 40000, 'name' => 'MYGRANT GLASS CO #018']);

    $jobA = summaryJob('LA-2026-0111');
    $jobB = summaryJob('LA-2026-0222');

    foreach ([$jobA, $jobB] as $job) {
        $txn->matches()->create([
            'kind' => BankMatchKind::PartPurchase->value,
            'job_id' => $job->id,
            'source' => 'manual',
        ]);
    }

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class, ['activeTab' => 'needs_review'])
        ->assertSee('LA-2026-0111')
        ->assertSee('LA-2026-0222');
});

it('still describes a single job-backed match', function () {
    $txn = summaryTxn();
    $job = summaryJob('LA-2026-0333');

    $txn->matches()->create([
        'kind' => BankMatchKind::JobPayment->value,
        'job_id' => $job->id,
        'source' => 'auto',
    ]);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class, ['activeTab' => 'needs_review'])
        ->assertSee('LA-2026-0333');
});
