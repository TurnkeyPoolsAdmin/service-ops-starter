<?php

namespace App\Filament\Pages;

use App\Enums\PaymentMethod;
use App\Filament\Concerns\GatedByPermission;
use App\Models\Employee;
use App\Services\JobLifecycle;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Tech Balances — the rolling cash each technician OWES us.
 *
 * When a tech collects more cash from customers than the pay they were owed, the
 * surplus rolls onto their balance here (set on the End-of-Day Summary). It keeps
 * rolling over — auto-offset whenever we'd next owe them pay — until they hand
 * the cash back, at which point you "Clear" it here. There is intentionally no
 * "mark paid": paying techs happens on the End-of-Day Summary; this tool only
 * tracks and clears the cash owed back to us. Gated by record_tech_payout.
 */
class TechSettlements extends Page implements HasTable
{
    use GatedByPermission;
    use InteractsWithTable;

    protected static function requiredPermission(): string
    {
        return 'record_tech_payout';
    }

    // Reached via the Admin > Finance hub, not its own sidebar item.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected string $view = 'filament.pages.tech-settlements';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $title = 'Tech Balances';

    protected static ?string $navigationLabel = 'Tech Balances';

    protected static ?int $navigationSort = 21;

    public function getSubheading(): ?string
    {
        return 'Cash each tech is holding that they owe us. It rolls over (auto-offset against '
            .'future pay on the End-of-Day Summary) until they hand it back — then clear it here.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Employee::query()->where('cash_balance_cents', '>', 0)
            )
            ->defaultSort('name', 'asc')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Technician')->weight('bold')->searchable(),
                TextColumn::make('entity.code')->label('Loc')->badge()->placeholder('—'),
                TextColumn::make('cash_balance_cents')->label('Owes us')
                    ->state(fn (Employee $r) => Money::format($r->cashOwedCents()))
                    ->badge()->color('danger')->alignEnd(),
            ])
            ->recordActions([
                $this->clearAction(),
            ])
            ->emptyStateHeading('No open balances')
            ->emptyStateDescription('When a tech collects more cash than their pay, the surplus shows here until they return it.')
            ->emptyStateIcon(Heroicon::OutlinedBanknotes);
    }

    /** Clear the cash a tech owes us once they've handed it back. */
    protected function clearAction(): Action
    {
        return Action::make('clear')
            ->label('Clear balance')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->modalHeading(fn (Employee $record) => 'Clear '.$record->name.'\'s balance')
            ->modalSubmitActionLabel('Clear balance')
            ->schema([
                Placeholder::make('summary')->label('')
                    ->content(fn (Employee $record) => new HtmlString(
                        '<div style="font-size:.9rem;line-height:1.5;"><strong>'.e($record->name).'</strong> owes us '
                        .'<strong>'.Money::format($record->cashOwedCents()).'</strong>. '
                        .'Recording this marks it returned and resets the balance to $0.</div>'
                    )),
                Select::make('method')->label('How they paid it back')
                    ->options(PaymentMethod::options()),
                TextInput::make('reference')->label('Reference (Zelle/Chase confirmation #, etc.)'),
                Textarea::make('note')->label('Note')->rows(2),
            ])
            ->action(function (Employee $record, array $data) {
                $settlement = app(JobLifecycle::class)->clearTechCashBalance(
                    $record,
                    isset($data['method']) ? PaymentMethod::from($data['method']) : null,
                    $data['reference'] ?? null,
                    $data['note'] ?? null,
                    auth()->user(),
                );

                if ($settlement === null) {
                    Notification::make()->title('Nothing to clear')
                        ->body('This tech has no open balance.')->warning()->send();

                    return;
                }

                Notification::make()->title('Balance cleared')
                    ->body(Money::format($settlement->amount_cents).' marked returned.')->success()->send();
            });
    }
}
