<?php

use App\Filament\Pages\Finances;
use App\Filament\Pages\InsuranceJobs;
use App\Filament\Pages\ManageIntegrations;
use App\Filament\Pages\Settings;
use App\Filament\Pages\TechPayments;
use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Models\BankTransaction;
use App\Models\Employee;
use App\Models\PlaidItem;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class]);

    $this->item = PlaidItem::create([
        'item_id' => 'item-fin-1',
        'access_token' => 'access-fin',
        'institution_name' => 'Chase',
    ]);

    $this->finance = Employee::create([
        'name' => 'Finance', 'email' => 'fin@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->finance->assignRole('finance');
});

function finTxn(array $overrides = []): BankTransaction
{
    return BankTransaction::create(array_merge([
        'plaid_item_id' => test()->item->id,
        'plaid_transaction_id' => 'txn-'.fake()->unique()->uuid(),
        'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking',
        'amount_cents' => 12345,
        'date' => '2026-07-01',
        'name' => 'Mystery charge',
        'pending' => false,
    ], $overrides));
}

it('grants the finance role only the bank ledger permission', function () {
    expect($this->finance->can('use_bank_ledger'))->toBeTrue()
        // Walled off from everything else.
        ->and($this->finance->can('manage_settings'))->toBeFalse()
        ->and($this->finance->can('access_admin'))->toBeFalse()
        ->and($this->finance->can('manage_jobs'))->toBeFalse()
        ->and($this->finance->can('record_payment'))->toBeFalse()
        ->and($this->finance->can('record_tech_payout'))->toBeFalse();
});

it('lets finance reach the bank ledger via the Finance hub, but nothing else', function () {
    $this->actingAs($this->finance);

    expect(BankTransactionResource::canAccess())->toBeTrue()
        ->and(Finances::canAccess())->toBeTrue()          // hub opens (one visible tile)
        ->and(Settings::canAccess())->toBeFalse()
        ->and(InsuranceJobs::canAccess())->toBeFalse()
        ->and(TechPayments::canAccess())->toBeFalse()
        ->and(ManageIntegrations::canAccess())->toBeFalse();

    // The hub shows only the Bank Transactions tile for finance.
    $this->get(Finances::getUrl())
        ->assertSuccessful()
        ->assertSee('Bank Transactions')
        ->assertDontSee('Insurance Jobs')
        ->assertDontSee('End-of-Day Summary');
});

it('lets finance flag a transaction in the ledger', function () {
    $txn = finTxn();

    Livewire\Livewire::actingAs($this->finance)
        ->test(ListBankTransactions::class)
        ->assertSuccessful()
        ->callTableAction('flag', $txn, data: ['review_note' => 'Ask bookkeeper'])
        ->assertHasNoTableActionErrors();

    expect($txn->refresh()->review_flagged)->toBeTrue()
        ->and($txn->flagged_by)->toBe($this->finance->id);
});

it('lets finance confirm a suggested match in the ledger', function () {
    $txn = finTxn(['match_status' => BankTransaction::STATUS_SUGGESTED]);

    Livewire\Livewire::actingAs($this->finance)
        ->test(ListBankTransactions::class)
        ->callTableAction('confirm', $txn)
        ->assertHasNoTableActionErrors();

    $txn->refresh();
    expect($txn->match_status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($txn->matched_by)->toBe($this->finance->id);
});

it('denies a dispatch user the bank ledger', function () {
    $dispatch = Employee::create([
        'name' => 'Dispatch', 'email' => 'disp@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $dispatch->assignRole('dispatch');

    $this->actingAs($dispatch);

    expect(BankTransactionResource::canAccess())->toBeFalse();
});
