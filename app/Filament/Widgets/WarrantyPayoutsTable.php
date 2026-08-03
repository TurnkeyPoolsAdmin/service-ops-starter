<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentMethod;
use App\Filament\Resources\Jobs\JobResource;
use App\Models\WarrantyClaim;
use App\Services\Warranty\WarrantyService;
use App\Support\Money;
use App\Support\Timezone;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The warranty-pay record on the End-of-Day summary. A return visit given extra
 * tech pay (rare — visits are normally unpaid) becomes owed when it's completed;
 * this widget lists what's outstanding plus anything paid in the last two weeks,
 * and settling it here writes a warranty_payout TechSettlement that reconciles
 * against Chase exactly like a normal payout. Deliberately NOT scoped to the
 * page's selected date — warranty pay is scarce and shouldn't vanish when you
 * step to another day.
 */
class WarrantyPayoutsTable extends BaseWidget
{
    protected static ?string $heading = 'Warranty visit pay';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (auth()->user()?->can('record_tech_payout') ?? false)
            && WarrantyClaim::query()->where('tech_pay_cents', '>', 0)->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => WarrantyClaim::query()
                ->with(['job', 'technician', 'entity'])
                ->where('tech_pay_cents', '>', 0)
                ->where('status', WarrantyClaim::STATUS_RESOLVED)
                ->where(fn (Builder $q) => $q
                    ->whereNull('tech_paid_at')
                    ->orWhere('resolved_at', '>=', now()->subDays(14)))
                // Outstanding first (null tech_paid_at sorts before a date desc-wise
                // once we secondary-sort), newest completion first.
                ->orderByRaw('tech_paid_at is null desc')
                ->orderByDesc('resolved_at'))
            ->columns([
                TextColumn::make('job.job_number')
                    ->label('Job')
                    ->url(fn (WarrantyClaim $r) => $r->job ? JobResource::getUrl('view', ['record' => $r->job]) : null)
                    ->weight('bold'),
                TextColumn::make('job.customer_name_snapshot')->label('Customer'),
                TextColumn::make('entity.code')->label('Loc')->badge(),
                TextColumn::make('technician.name')->label('Tech')->placeholder('—'),
                TextColumn::make('completed')->label('Completed')
                    ->state(fn (WarrantyClaim $r) => Timezone::toDisplay($r->resolved_at)?->format('M j, Y') ?? '—'),
                TextColumn::make('tech_pay_cents')->label('Pay')
                    ->state(fn (WarrantyClaim $r) => Money::format((int) $r->tech_pay_cents))->alignEnd(),
                TextColumn::make('status')->label('Status')
                    ->state(fn (WarrantyClaim $r) => $r->tech_paid_at ? 'Paid' : 'To pay')
                    ->badge()
                    ->color(fn (WarrantyClaim $r) => $r->tech_paid_at ? 'success' : 'warning')
                    ->alignCenter(),
            ])
            ->recordActions([
                $this->markPaidAction(),
            ])
            ->paginated(false)
            ->emptyStateHeading('No warranty pay')
            ->emptyStateDescription('Return visits given extra pay show here once completed.')
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck);
    }

    protected function markPaidAction(): Action
    {
        return Action::make('markWarrantyPaid')
            ->label('Mark paid')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (WarrantyClaim $record) => $record->isWarrantyPayOwed())
            ->modalHeading(fn (WarrantyClaim $record) => 'Pay '.($record->technician?->name ?? 'tech')
                .' '.Money::format((int) $record->tech_pay_cents).' for the warranty visit')
            ->schema([
                Select::make('method')->label('Method')
                    ->options(PaymentMethod::options())
                    ->default(PaymentMethod::Zelle->value)
                    ->required(),
                TextInput::make('reference')->label('Reference (optional)'),
            ])
            ->action(function (WarrantyClaim $record, array $data) {
                $settlement = app(WarrantyService::class)->markTechPaid(
                    $record,
                    PaymentMethod::tryFrom($data['method'] ?? '') ?? PaymentMethod::Zelle,
                    $data['reference'] ?: null,
                    auth()->user(),
                );

                if ($settlement === null) {
                    Notification::make()->title('Nothing to settle')
                        ->body('This warranty visit has no outstanding pay.')->warning()->send();

                    return;
                }

                Notification::make()->title('Warranty pay recorded')
                    ->body('Paid '.Money::format($settlement->amount_cents).' to '.($record->technician?->name ?? 'tech').'.')
                    ->success()->send();
            });
    }
}
