<?php

use App\Models\BankTransaction;
use App\Models\BankTransactionMatch;
use App\Models\Employee;
use App\Models\PlaidItem;

function resetTxn(array $overrides = []): BankTransaction
{
    $item = PlaidItem::firstOrCreate(
        ['item_id' => 'reset-item-1'],
        ['access_token' => 'access-test', 'institution_name' => 'Chase'],
    );

    return BankTransaction::create(array_merge([
        'plaid_item_id' => $item->id,
        'plaid_transaction_id' => 'txn-'.fake()->unique()->uuid(),
        'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking',
        'amount_cents' => 10000,
        'date' => '2026-07-01',
        'name' => 'Some transaction',
        'pending' => false,
        'match_status' => BankTransaction::STATUS_MATCHED,
        'matched_at' => now(),
    ], $overrides));
}

test('deletes all match rows and resets every transaction to unmatched when forced', function () {
    $employee = Employee::create([
        'name' => 'Admin', 'email' => 'reset-admin@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);

    $matched = resetTxn(['matched_by' => $employee->id]);
    $suggested = resetTxn(['match_status' => BankTransaction::STATUS_SUGGESTED, 'matched_at' => null]);

    BankTransactionMatch::create([
        'bank_transaction_id' => $matched->id,
        'kind' => 'misc',
        'misc_category' => 'bank_fee',
        'source' => 'auto',
    ]);

    $this->artisan('matching:execute-reset', ['--force' => true])->assertExitCode(0);

    expect(BankTransactionMatch::count())->toBe(0);
    expect(BankTransaction::count())->toBe(2);

    foreach ([$matched->fresh(), $suggested->fresh()] as $txn) {
        expect($txn->match_status)->toBe(BankTransaction::STATUS_UNMATCHED);
        expect($txn->matched_at)->toBeNull();
        expect($txn->matched_by)->toBeNull();
    }
});

test('aborts and deletes nothing when confirmation is declined', function () {
    $matched = resetTxn();

    BankTransactionMatch::create([
        'bank_transaction_id' => $matched->id,
        'kind' => 'misc',
        'misc_category' => 'bank_fee',
        'source' => 'auto',
    ]);

    $this->artisan('matching:execute-reset')
        ->expectsConfirmation('Have you already run matching:backup-before-reset and verified the counts match the live tables?', 'no')
        ->assertExitCode(1);

    expect(BankTransactionMatch::count())->toBe(1);
    expect($matched->fresh()->match_status)->toBe(BankTransaction::STATUS_MATCHED);
});

test('succeeds as a no-op when there is nothing to reset', function () {
    $this->artisan('matching:execute-reset', ['--force' => true])->assertExitCode(0);

    expect(BankTransactionMatch::count())->toBe(0);
    expect(BankTransaction::count())->toBe(0);
});
