<?php

namespace App\Filament\Resources\BankTransactions;

use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Filament\Resources\BankTransactions\Tables\BankTransactionsTable;
use App\Models\BankTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only ledger of bank/credit transactions pulled from Plaid (Chase checking
 * + credit card). Populated by the daily bank:pull-transactions command. Gated on
 * use_bank_ledger (held by the finance role + super_admin) since it exposes company
 * banking detail — the reconciliation work (match/flag/confirm) lives here.
 */
class BankTransactionResource extends Resource
{
    protected static ?string $model = BankTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Bank Transactions';

    protected static ?int $navigationSort = 22;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('use_bank_ledger') ?? false;
    }

    // Reached via the Admin > Finance hub, not its own sidebar item.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return BankTransactionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankTransactions::route('/'),
        ];
    }
}
