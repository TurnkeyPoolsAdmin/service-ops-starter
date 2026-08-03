<?php

use App\Enums\IssueType;
use App\Models\BankTransaction;
use App\Models\Issue;
use App\Models\PlaidItem;
use App\Services\Plaid\PlaidClient;
use Illuminate\Support\Facades\Artisan;

/**
 * Plaid's transactions/sync upsert rewrites amount_cents/date/authorized_date
 * unconditionally on every pull, including for a transaction that's already
 * matched or suggested. Nothing used to catch that, which is what let 5 real
 * tech-payout matches (all genuinely exact-amount/same-day when created) look
 * wrong in hindsight once a later sync silently moved one of those fields.
 * This covers the detection: flag matched/suggested rows whose key fields
 * change, via the same Issue-queue pattern as TransactionMismatch — never
 * auto-unmatch, never auto-reverse, human review only.
 */
beforeEach(function () {
    $this->item = PlaidItem::create([
        'item_id' => 'item-drift-1',
        'access_token' => 'access-test',
        'institution_name' => 'Chase',
    ]);
});

function driftSyncResponse(array $added = [], array $modified = []): array
{
    return [
        'added' => $added,
        'modified' => $modified,
        'removed' => [],
        'next_cursor' => 'cursor-'.uniqid(),
        'has_more' => false,
        'accounts' => [],
    ];
}

function driftPlaidTxn(array $overrides = []): array
{
    return array_merge([
        'transaction_id' => 'plaid-drift-1',
        'account_id' => 'acc-1',
        'amount' => 330.00,
        'iso_currency_code' => 'USD',
        'date' => '2026-07-07',
        'authorized_date' => '2026-07-07',
        'name' => 'Zelle to Jose',
        'pending' => false,
    ], $overrides);
}

it('flags a MATCHED transaction whose amount changes on a later sync', function () {
    $this->mock(PlaidClient::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('syncTransactions')->once()->andReturn(driftSyncResponse(added: [driftPlaidTxn()]));
        $mock->shouldReceive('getBalances')->once()->andReturn(null);
    });
    Artisan::call('bank:pull-transactions');

    $txn = BankTransaction::where('plaid_transaction_id', 'plaid-drift-1')->first();
    $txn->update(['match_status' => BankTransaction::STATUS_MATCHED]);

    $this->mock(PlaidClient::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('syncTransactions')->once()->andReturn(driftSyncResponse(modified: [driftPlaidTxn(['amount' => 258.20])]));
        $mock->shouldReceive('getBalances')->once()->andReturn(null);
    });
    Artisan::call('bank:pull-transactions');

    $txn->refresh();
    expect($txn->amount_cents)->toBe(25820); // Plaid's value still wins - never blocked

    $issue = Issue::open()->where('type', IssueType::MatchedTransactionFieldsChanged->value)->first();
    expect($issue)->not->toBeNull()
        ->and($issue->details['changed_fields'])->toBe('amount_cents')
        ->and($issue->details['old_amount_cents'])->toBe(33000)
        ->and($issue->details['new_amount_cents'])->toBe(25820)
        ->and($issue->details['match_status'])->toBe(BankTransaction::STATUS_MATCHED);
});

it('flags a SUGGESTED transaction whose date changes on a later sync', function () {
    $this->mock(PlaidClient::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('syncTransactions')->once()->andReturn(driftSyncResponse(added: [driftPlaidTxn()]));
        $mock->shouldReceive('getBalances')->once()->andReturn(null);
    });
    Artisan::call('bank:pull-transactions');

    $txn = BankTransaction::where('plaid_transaction_id', 'plaid-drift-1')->first();
    $txn->update(['match_status' => BankTransaction::STATUS_SUGGESTED]);

    $this->mock(PlaidClient::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('syncTransactions')->once()->andReturn(driftSyncResponse(modified: [
            driftPlaidTxn(['authorized_date' => '2026-07-09']),
        ]));
        $mock->shouldReceive('getBalances')->once()->andReturn(null);
    });
    Artisan::call('bank:pull-transactions');

    $issue = Issue::open()->where('type', IssueType::MatchedTransactionFieldsChanged->value)->first();
    expect($issue)->not->toBeNull()
        ->and($issue->details['changed_fields'])->toBe('authorized_date')
        ->and($issue->details['old_authorized_date'])->toBe('2026-07-07')
        ->and($issue->details['new_authorized_date'])->toBe('2026-07-09');
});

it('never flags an UNMATCHED transaction whose fields change - that is the normal, expected case', function () {
    $this->mock(PlaidClient::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('syncTransactions')->once()->andReturn(driftSyncResponse(added: [driftPlaidTxn()]));
        $mock->shouldReceive('getBalances')->once()->andReturn(null);
    });
    Artisan::call('bank:pull-transactions');

    // Deliberately left as the default STATUS_UNMATCHED - a pending transaction's
    // amount/date settling as it posts is routine and must not raise anything.
    $this->mock(PlaidClient::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('syncTransactions')->once()->andReturn(driftSyncResponse(modified: [
            driftPlaidTxn(['amount' => 258.20, 'date' => '2026-07-08']),
        ]));
        $mock->shouldReceive('getBalances')->once()->andReturn(null);
    });
    Artisan::call('bank:pull-transactions');

    expect(Issue::where('type', IssueType::MatchedTransactionFieldsChanged->value)->count())->toBe(0);
});

it('raises nothing when a matched transaction re-syncs with identical fields', function () {
    $this->mock(PlaidClient::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('syncTransactions')->once()->andReturn(driftSyncResponse(added: [driftPlaidTxn()]));
        $mock->shouldReceive('getBalances')->once()->andReturn(null);
    });
    Artisan::call('bank:pull-transactions');

    BankTransaction::where('plaid_transaction_id', 'plaid-drift-1')->first()
        ->update(['match_status' => BankTransaction::STATUS_MATCHED]);

    // Same amount/date/authorized_date as the first sync - only merchant_name differs.
    $this->mock(PlaidClient::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('syncTransactions')->once()->andReturn(driftSyncResponse(modified: [
            driftPlaidTxn(['merchant_name' => 'Zelle']),
        ]));
        $mock->shouldReceive('getBalances')->once()->andReturn(null);
    });
    Artisan::call('bank:pull-transactions');

    expect(Issue::where('type', IssueType::MatchedTransactionFieldsChanged->value)->count())->toBe(0);
});

it('never blocks or reverts the write - Plaid data always wins, this is detection only', function () {
    $this->mock(PlaidClient::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('syncTransactions')->once()->andReturn(driftSyncResponse(added: [driftPlaidTxn()]));
        $mock->shouldReceive('getBalances')->once()->andReturn(null);
    });
    Artisan::call('bank:pull-transactions');

    $txn = BankTransaction::where('plaid_transaction_id', 'plaid-drift-1')->first();
    $txn->update(['match_status' => BankTransaction::STATUS_MATCHED]);

    $this->mock(PlaidClient::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('syncTransactions')->once()->andReturn(driftSyncResponse(modified: [
            driftPlaidTxn(['amount' => 999.99]),
        ]));
        $mock->shouldReceive('getBalances')->once()->andReturn(null);
    });
    Artisan::call('bank:pull-transactions');

    $txn->refresh();
    // The write still happened - this feature only flags, it never withholds the sync.
    expect($txn->amount_cents)->toBe(99999)
        ->and($txn->match_status)->toBe(BankTransaction::STATUS_MATCHED); // untouched - no auto-unmatch
});
