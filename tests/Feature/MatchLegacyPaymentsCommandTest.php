<?php

use App\Models\BankTransaction;
use App\Models\LegacyManualPayment;
use App\Models\PlaidItem;

beforeEach(function () {
    test()->item = PlaidItem::create([
        'item_id' => 'item-legacy-cmd', 'access_token' => 'access-test', 'institution_name' => 'Chase',
    ]);
});

it('dry-runs by default and writes nothing', function () {
    LegacyManualPayment::create([
        'source_row' => 5001, 'payment_date' => '2026-01-15', 'customer_name' => 'Test Customer',
        'paid_cents' => 25000, 'payment_method' => 'Zelle', 'status' => LegacyManualPayment::STATUS_PENDING,
    ]);
    BankTransaction::create([
        'plaid_item_id' => test()->item->id, 'plaid_transaction_id' => 'txn-cmd-1', 'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking', 'amount_cents' => -25000, 'date' => '2026-01-16',
        'name' => 'Zelle from Test Customer', 'pending' => false, 'match_status' => BankTransaction::STATUS_UNMATCHED,
    ]);

    test()->artisan('legacy-payments:match')->assertSuccessful();

    expect(LegacyManualPayment::first()->status)->toBe(LegacyManualPayment::STATUS_PENDING)
        ->and(BankTransaction::first()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED);
});

it('writes real matches with --live after confirmation', function () {
    LegacyManualPayment::create([
        'source_row' => 5002, 'payment_date' => '2026-01-15', 'customer_name' => 'Test Customer',
        'paid_cents' => 25000, 'payment_method' => 'Zelle', 'status' => LegacyManualPayment::STATUS_PENDING,
    ]);
    BankTransaction::create([
        'plaid_item_id' => test()->item->id, 'plaid_transaction_id' => 'txn-cmd-2', 'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking', 'amount_cents' => -25000, 'date' => '2026-01-16',
        'name' => 'Zelle from Test Customer', 'pending' => false, 'match_status' => BankTransaction::STATUS_UNMATCHED,
    ]);

    test()->artisan('legacy-payments:match', ['--live' => true])
        ->expectsConfirmation('This writes matches and pre-tracking exclusions against real data. Continue?', 'yes')
        ->assertSuccessful();

    expect(LegacyManualPayment::first()->status)->toBe(LegacyManualPayment::STATUS_MATCHED)
        ->and(BankTransaction::first()->match_status)->toBe(BankTransaction::STATUS_SUGGESTED);
});

it('aborts --live without writing anything when declined at the confirmation prompt', function () {
    LegacyManualPayment::create([
        'source_row' => 5003, 'payment_date' => '2026-01-15', 'customer_name' => 'Test Customer',
        'paid_cents' => 25000, 'payment_method' => 'Zelle', 'status' => LegacyManualPayment::STATUS_PENDING,
    ]);

    test()->artisan('legacy-payments:match', ['--live' => true])
        ->expectsConfirmation('This writes matches and pre-tracking exclusions against real data. Continue?', 'no')
        ->assertSuccessful();

    expect(LegacyManualPayment::first()->status)->toBe(LegacyManualPayment::STATUS_PENDING);
});

it('lists confident matches with the customer, amount, and matched transaction', function () {
    LegacyManualPayment::create([
        'source_row' => 5004, 'payment_date' => '2026-01-15', 'customer_name' => 'Gilbert Camacho',
        'paid_cents' => 25000, 'payment_method' => 'Zelle', 'status' => LegacyManualPayment::STATUS_PENDING,
    ]);
    BankTransaction::create([
        'plaid_item_id' => test()->item->id, 'plaid_transaction_id' => 'txn-cmd-4', 'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking', 'amount_cents' => -25000, 'date' => '2026-01-16',
        'name' => 'Zelle from Gilbert Camacho', 'pending' => false, 'match_status' => BankTransaction::STATUS_UNMATCHED,
    ]);

    test()->artisan('legacy-payments:match')
        ->expectsOutputToContain('--- Confident matches (1) ---')
        ->expectsOutputToContain('Gilbert Camacho — $250.00 (2026-01-15) -> txn')
        ->assertSuccessful();
});

it('lists ambiguous rows with their full candidate set', function () {
    LegacyManualPayment::create([
        'source_row' => 5005, 'payment_date' => '2026-01-15', 'customer_name' => 'Some Customer',
        'paid_cents' => 25000, 'payment_method' => 'Zelle', 'status' => LegacyManualPayment::STATUS_PENDING,
    ]);
    BankTransaction::create([
        'plaid_item_id' => test()->item->id, 'plaid_transaction_id' => 'txn-cmd-5a', 'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking', 'amount_cents' => -25000, 'date' => '2026-01-16',
        'name' => 'Zelle from Unrelated Person', 'pending' => false, 'match_status' => BankTransaction::STATUS_UNMATCHED,
    ]);
    BankTransaction::create([
        'plaid_item_id' => test()->item->id, 'plaid_transaction_id' => 'txn-cmd-5b', 'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking', 'amount_cents' => -25000, 'date' => '2026-01-17',
        'name' => 'Zelle from Another Person', 'pending' => false, 'match_status' => BankTransaction::STATUS_UNMATCHED,
    ]);

    test()->artisan('legacy-payments:match')
        ->expectsOutputToContain('--- Ambiguous (1) ---')
        ->expectsOutputToContain('Some Customer — $250.00 (2026-01-15):')
        ->expectsOutputToContain('candidate txn')
        ->assertSuccessful();
});

it('splits no-candidate rows by whether they predate the bank ledger\'s earliest transaction', function () {
    BankTransaction::create([
        'plaid_item_id' => test()->item->id, 'plaid_transaction_id' => 'txn-cmd-6', 'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking', 'amount_cents' => -99999, 'date' => '2026-04-02',
        'name' => 'unrelated', 'pending' => false, 'match_status' => BankTransaction::STATUS_UNMATCHED,
    ]);
    LegacyManualPayment::create([
        'source_row' => 5006, 'payment_date' => '2026-01-15', 'customer_name' => 'Before Coverage',
        'paid_cents' => 25000, 'payment_method' => 'Zelle', 'status' => LegacyManualPayment::STATUS_PENDING,
    ]);
    LegacyManualPayment::create([
        'source_row' => 5007, 'payment_date' => '2026-05-01', 'customer_name' => 'After Coverage',
        'paid_cents' => 30000, 'payment_method' => 'Zelle', 'status' => LegacyManualPayment::STATUS_PENDING,
    ]);

    test()->artisan('legacy-payments:match')
        ->expectsOutputToContain("Bank ledger's earliest transaction on record: 2026-04-02")
        ->expectsOutputToContain('Before bank coverage start (predates bank data - expected, not a failure): 1')
        ->expectsOutputToContain('On/after bank coverage start (genuinely unexplained - worth a look): 1')
        ->assertSuccessful();
});
