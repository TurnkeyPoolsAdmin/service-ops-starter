<?php

use App\Models\BankAccount;
use App\Models\PlaidItem;
use App\Services\Plaid\PlaidClient;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->item = PlaidItem::create([
        'item_id' => 'item-bal-1',
        'access_token' => 'access-test',
        'institution_name' => 'Chase',
    ]);
});

/** An empty /transactions/sync page (cursor caught up — no transactions). */
function emptySyncResponse(): array
{
    return [
        'added' => [],
        'modified' => [],
        'removed' => [],
        'next_cursor' => 'cursor-1',
        'has_more' => false,
        'accounts' => [],
    ];
}

/** The accounts array as /accounts/balance/get returns it (two accounts, balances). */
function fakeBalanceAccounts(): array
{
    return [
        [
            'account_id' => 'acc-checking',
            'official_name' => 'Chase Total Checking',
            'name' => 'Checking',
            'mask' => '1234',
            'type' => 'depository',
            'subtype' => 'checking',
            'balances' => ['current' => 15234.56, 'available' => 15000.00, 'limit' => null],
        ],
        [
            'account_id' => 'acc-card',
            'official_name' => 'Chase Ink Business',
            'name' => 'Credit Card',
            'mask' => '5678',
            'type' => 'credit',
            'subtype' => 'credit card',
            'balances' => ['current' => 4200.00, 'available' => 20800.00, 'limit' => 25000.00],
        ],
    ];
}

it('persists per-account balances fetched via /accounts/balance/get', function () {
    $this->mock(PlaidClient::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('syncTransactions')->once()->andReturn(emptySyncResponse());
        $mock->shouldReceive('getBalances')->once()->andReturn(fakeBalanceAccounts());
    });

    Artisan::call('bank:pull-transactions');

    expect(BankAccount::count())->toBe(2);

    $checking = BankAccount::where('plaid_account_id', 'acc-checking')->first();
    expect($checking->type)->toBe('depository')
        ->and($checking->current_balance_cents)->toBe(1523456)
        ->and($checking->available_balance_cents)->toBe(1500000)
        ->and($checking->plaid_item_id)->toBe($this->item->id);

    $card = BankAccount::where('plaid_account_id', 'acc-card')->first();
    expect($card->type)->toBe('credit')
        ->and($card->current_balance_cents)->toBe(420000) // amount owed
        ->and($card->limit_cents)->toBe(2500000);
});

it('updates balances in place on the next pull rather than duplicating', function () {
    $second = fakeBalanceAccounts();
    $second[0]['balances']['current'] = 9999.99;

    $this->mock(PlaidClient::class, function ($mock) use ($second) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('syncTransactions')->andReturn(emptySyncResponse());
        $mock->shouldReceive('getBalances')->andReturn(fakeBalanceAccounts(), $second);
    });

    Artisan::call('bank:pull-transactions');
    Artisan::call('bank:pull-transactions');

    expect(BankAccount::count())->toBe(2)
        ->and(BankAccount::where('plaid_account_id', 'acc-checking')->value('current_balance_cents'))
        ->toBe(999999);
});

it('leaves last-known balances untouched when the balance fetch errors', function () {
    $this->mock(PlaidClient::class, function ($mock) {
        $mock->shouldReceive('configured')->andReturn(true);
        $mock->shouldReceive('syncTransactions')->andReturn(emptySyncResponse());
        $mock->shouldReceive('getBalances')->andReturn(fakeBalanceAccounts(), null);
    });

    Artisan::call('bank:pull-transactions'); // seeds balances
    Artisan::call('bank:pull-transactions'); // getBalances returns null → no wipe

    expect(BankAccount::count())->toBe(2)
        ->and(BankAccount::where('plaid_account_id', 'acc-checking')->value('current_balance_cents'))
        ->toBe(1523456);
});
