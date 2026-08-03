<?php

use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Models\BankTransaction;
use App\Models\Employee;
use App\Models\PlaidItem;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class]);

    $this->item = PlaidItem::create([
        'item_id' => 'item-flag-1',
        'access_token' => 'access-flag',
        'institution_name' => 'Chase',
    ]);

    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'flagadmin@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->admin->assignRole('super_admin');
});

function flagTxn(array $overrides = []): BankTransaction
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

it('flags a transaction for review with a note', function () {
    $txn = flagTxn();

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('flag', $txn, data: ['review_note' => 'Check with bookkeeper'])
        ->assertHasNoTableActionErrors();

    $txn->refresh();
    expect($txn->review_flagged)->toBeTrue()
        ->and($txn->review_note)->toBe('Check with bookkeeper')
        ->and($txn->flagged_at)->not->toBeNull()
        ->and($txn->flagged_by)->toBe($this->admin->id);
});

it('flags without a note', function () {
    $txn = flagTxn();

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('flag', $txn, data: [])
        ->assertHasNoTableActionErrors();

    expect($txn->refresh()->review_flagged)->toBeTrue()
        ->and($txn->review_note)->toBeNull();
});

it('edits the note on a flagged transaction', function () {
    $txn = flagTxn(['review_flagged' => true, 'review_note' => 'old', 'flagged_at' => now(), 'flagged_by' => $this->admin->id]);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('editFlagNote', $txn, data: ['review_note' => 'new note'])
        ->assertHasNoTableActionErrors();

    expect($txn->refresh()->review_note)->toBe('new note');
});

it('clears a flag', function () {
    $txn = flagTxn(['review_flagged' => true, 'review_note' => 'x', 'flagged_at' => now(), 'flagged_by' => $this->admin->id]);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('unflag', $txn)
        ->assertHasNoTableActionErrors();

    $txn->refresh();
    expect($txn->review_flagged)->toBeFalse()
        ->and($txn->review_note)->toBeNull()
        ->and($txn->flagged_at)->toBeNull()
        ->and($txn->flagged_by)->toBeNull();
});

it('highlights flagged rows for a super_admin', function () {
    flagTxn(['review_flagged' => true]);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->assertSuccessful()
        ->assertSee('gd-flagged-row');
});
