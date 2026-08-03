<?php

use App\Models\BankTransaction;
use App\Models\BankTransactionMatch;
use App\Models\Employee;
use App\Models\PlaidItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function backupTxn(array $overrides = []): BankTransaction
{
    $item = PlaidItem::firstOrCreate(
        ['item_id' => 'backup-item-1'],
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

test('archives every match row and every transaction match state before any deletion', function () {
    $matched = backupTxn();
    $unmatched = backupTxn(['match_status' => BankTransaction::STATUS_UNMATCHED, 'matched_at' => null]);

    BankTransactionMatch::create([
        'bank_transaction_id' => $matched->id,
        'kind' => 'misc',
        'misc_category' => 'bank_fee',
        'source' => 'auto',
    ]);
    BankTransactionMatch::create([
        'bank_transaction_id' => $matched->id,
        'kind' => 'misc',
        'misc_category' => 'transfer',
        'source' => 'manual',
        'note' => 'second link on the same transaction',
    ]);

    $this->artisan('matching:backup-before-reset')->assertExitCode(0);

    $matchesArchiveTable = collect(Schema::getTables())
        ->pluck('name')
        ->first(fn ($name) => str_starts_with($name, 'bank_transaction_matches_archive_'));
    $stateArchiveTable = collect(Schema::getTables())
        ->pluck('name')
        ->first(fn ($name) => str_starts_with($name, 'bank_transactions_match_state_archive_'));

    expect($matchesArchiveTable)->not->toBeNull();
    expect($stateArchiveTable)->not->toBeNull();

    expect(DB::table($matchesArchiveTable)->count())->toBe(2);
    expect(DB::table($stateArchiveTable)->count())->toBe(2);

    // Original rows are untouched — this command only copies, never deletes.
    expect(BankTransactionMatch::count())->toBe(2);
    expect(BankTransaction::count())->toBe(2);

    $archivedRow = DB::table($matchesArchiveTable)->where('misc_category', 'transfer')->first();
    expect($archivedRow->note)->toBe('second link on the same transaction');
    expect($archivedRow->source)->toBe('manual');

    $archivedState = DB::table($stateArchiveTable)->where('bank_transaction_id', $matched->id)->first();
    expect($archivedState->match_status)->toBe(BankTransaction::STATUS_MATCHED);
});

test('succeeds with empty archive tables when there is nothing to back up yet', function () {
    $this->artisan('matching:backup-before-reset')->assertExitCode(0);

    $matchesArchiveTable = collect(Schema::getTables())
        ->pluck('name')
        ->first(fn ($name) => str_starts_with($name, 'bank_transaction_matches_archive_'));
    $stateArchiveTable = collect(Schema::getTables())
        ->pluck('name')
        ->first(fn ($name) => str_starts_with($name, 'bank_transactions_match_state_archive_'));

    expect(DB::table($matchesArchiveTable)->count())->toBe(0);
    expect(DB::table($stateArchiveTable)->count())->toBe(0);
});
