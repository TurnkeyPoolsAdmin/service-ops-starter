<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\IsTileHub;
use App\Filament\Resources\BankTransactions\BankTransactionResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Admin > Finance hub. A single landing page with a tile per finance tool
 * (End-of-Day Summary, Tech Balances, Bank Transactions). Each tile shows only
 * if the logged-in user can access it, mirroring the underlying page/resource
 * gating. Those tools no longer register their own sidebar item — they live here.
 */
class Finances extends Page
{
    use IsTileHub;

    protected string $view = 'filament.pages.tile-hub';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Finance';

    protected static ?string $title = 'Finance';

    protected static ?int $navigationSort = 1;

    protected static function tileClasses(): array
    {
        return [
            ['class' => TechPayments::class, 'label' => 'End-of-Day Summary', 'icon' => Heroicon::OutlinedBanknotes],
            ['class' => TechSettlements::class, 'label' => 'Tech Balances', 'icon' => Heroicon::OutlinedScale],
            ['class' => OwedFromXpro::class, 'label' => 'Owed from Xpro', 'icon' => Heroicon::OutlinedBuildingStorefront],
            ['class' => Dividends::class, 'label' => 'Dividend Payouts', 'icon' => Heroicon::OutlinedBanknotes],
            ['class' => InsuranceJobs::class, 'label' => 'Insurance Jobs', 'icon' => Heroicon::OutlinedShieldCheck],
            ['class' => BankTransactionResource::class, 'label' => 'Bank Transactions', 'icon' => Heroicon::OutlinedCreditCard],
            ['class' => PartsInvoices::class, 'label' => 'Parts Invoices', 'icon' => Heroicon::OutlinedDocumentText],
            ['class' => BookkeepingHealth::class, 'label' => 'Bookkeeping Health', 'icon' => Heroicon::OutlinedCheckBadge],
        ];
    }
}
