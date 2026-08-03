<?php

namespace App\Filament\Pages;

use App\Enums\BankMatchKind;
use App\Filament\Concerns\GatedByPermission;
use App\Models\BankTransaction;
use App\Support\Money;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Dividend payouts — outgoing Zelle to an owner (Pat Owner / Sam Partner),
 * classified apart from tech payouts. One row per bank transaction matched as a
 * dividend (auto-matched by the recipient's name, or matched by hand). Read-only;
 * reached via the Admin > Finance hub. Reconciliation happens on the Bank
 * Transactions ledger — this is the owner-facing "what did we pay ourselves" view.
 */
class Dividends extends Page implements HasTable
{
    use GatedByPermission;
    use InteractsWithTable;

    // Admin-only: access_admin is held by admin + super_admin, not dispatch.
    protected static function requiredPermission(): string
    {
        return 'access_admin';
    }

    // Reached via the Admin > Finance hub, not its own sidebar item.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected string $view = 'filament.pages.dividends';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $title = 'Dividend Payouts';

    protected static ?string $navigationLabel = 'Dividend Payouts';

    protected static ?int $navigationSort = 25;

    public function getSubheading(): ?string
    {
        return 'Outgoing Zelle to the owners (Pat Owner / Sam Partner), tracked apart from tech payouts. '
            .'Classified automatically by the recipient name on the bank ledger.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                BankTransaction::query()
                    ->whereHas('matches', fn (Builder $q) => $q->where('kind', BankMatchKind::Dividend->value))
                    ->with('matches')
            )
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('date')->label('Date')
                    ->formatStateUsing(fn (?string $state) => $state ? Carbon::parse($state)->format('M j, Y') : '—')
                    ->sortable(),
                TextColumn::make('recipient')->label('Paid to')->weight('bold')->badge()->color('info')
                    ->state(fn (BankTransaction $r) => $r->matches
                        ->firstWhere('kind', BankMatchKind::Dividend)?->dividendRecipientName() ?? 'Owner'),
                TextColumn::make('name')->label('Description')->limit(45)
                    ->tooltip(fn (BankTransaction $r) => $r->name),
                TextColumn::make('account_name')->label('Account')->placeholder('—')->toggleable(),
                // amount_cents is Plaid-signed (positive = money OUT); a dividend is
                // always money out, so show the plain dollar amount.
                TextColumn::make('amount_cents')->label('Amount')->alignEnd()->sortable()
                    ->formatStateUsing(fn (int $state) => Money::format(abs($state)))
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->using(fn ($query) => Money::format(
                                (int) $query->pluck('amount_cents')->sum(fn ($c) => abs((int) $c))
                            ))
                    ),
            ])
            ->emptyStateHeading('No dividend payouts yet')
            ->emptyStateDescription('Outgoing Zelle to an owner will appear here once it lands on the bank ledger.')
            ->emptyStateIcon(Heroicon::OutlinedBanknotes);
    }
}
