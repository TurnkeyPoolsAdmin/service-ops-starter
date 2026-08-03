<?php

namespace App\Filament\Widgets;

use App\Models\BankAccount;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * At-a-glance account health for the Bank Transactions ledger: cash sitting in
 * checking vs. what's owed on the credit card, plus the net. Fed by the balances
 * captured on every Plaid sync (see PullBankTransactions::upsertBalances).
 */
class BankBalancesOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    protected ?string $heading = 'Account balances';

    public static function canView(): bool
    {
        return (auth()->user()?->can('manage_settings') ?? false)
            && BankAccount::query()->exists();
    }

    protected function getStats(): array
    {
        $checkingCents = (int) BankAccount::query()->depository()->sum('current_balance_cents');
        $creditCents = (int) BankAccount::query()->credit()->sum('current_balance_cents');
        $netCents = $checkingCents - $creditCents;

        $updatedAt = BankAccount::query()->max('balance_updated_at');
        $asOf = $updatedAt ? 'As of '.Carbon::parse($updatedAt)->diffForHumans() : null;

        return [
            Stat::make('Checking balance', Money::format($checkingCents))
                ->description($asOf)
                ->color('success'),

            Stat::make('Credit card balance', Money::format($creditCents))
                ->description('Amount owed')
                ->color($creditCents > 0 ? 'danger' : 'gray'),

            Stat::make('Net cash position', Money::format($netCents))
                ->description('Checking minus card')
                ->color($netCents >= 0 ? 'success' : 'danger'),
        ];
    }
}
