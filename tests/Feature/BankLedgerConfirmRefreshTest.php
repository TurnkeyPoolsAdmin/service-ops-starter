<?php

use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Models\BankTransaction;
use App\Models\Employee;
use App\Models\PlaidItem;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class]);

    $this->item = PlaidItem::create([
        'item_id' => 'item-confirm-1',
        'access_token' => 'access-confirm',
        'institution_name' => 'Chase',
    ]);

    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'confirmadmin@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->admin->assignRole('super_admin');
});

function suggestedTxn(array $overrides = []): BankTransaction
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

it('writes the confirmed status to the database', function () {
    $txn = suggestedTxn();

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class, ['activeTab' => 'needs_review'])
        ->callTableAction('confirm', $txn)
        ->assertHasNoTableActionErrors();

    $txn->refresh();
    expect($txn->match_status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($txn->matched_at)->not->toBeNull()
        ->and($txn->matched_by)->toBe($this->admin->id);
});

it('drops the row out of Needs review in the same render as the confirm click', function () {
    $txn = suggestedTxn();

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class, ['activeTab' => 'needs_review'])
        ->assertCanSeeTableRecords([$txn])
        ->callTableAction('confirm', $txn)
        ->assertHasNoTableActionErrors()
        ->assertCanNotSeeTableRecords([$txn]);
});
