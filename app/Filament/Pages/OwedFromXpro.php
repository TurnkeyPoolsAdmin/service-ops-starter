<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Jobs\JobResource;
use App\Models\Job;
use App\Services\JobLifecycle;
use App\Support\Money;
use App\Support\Timezone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Owed from Xpro — money the Xpro team collected from customers on our behalf
 * (paid via a Klarna / SwiftSwipe / Other "- Xpro Team" method) that we still
 * have to collect back from them. One row per such job until "Mark collected"
 * stamps it. Admin-only, reached via the Admin > Finance hub.
 */
class OwedFromXpro extends Page implements HasTable
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

    protected string $view = 'filament.pages.owed-from-xpro';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $title = 'Owed from Xpro';

    protected static ?string $navigationLabel = 'Owed from Xpro';

    protected static ?int $navigationSort = 24;

    public function getSubheading(): ?string
    {
        return 'Jobs the Xpro team collected payment on (Klarna / SwiftSwipe / Other) — money we still '
            .'need to collect back from them. Mark each collected once the money is in hand.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Job::query()->owedFromXpro()->with(['entity', 'technician', 'customer', 'payments'])
            )
            ->defaultSort('payment_received_at', 'asc')
            ->columns([
                TextColumn::make('entity.code')->label('Loc')->badge()->placeholder('—'),
                TextColumn::make('customer_name_snapshot')->label('Customer')->weight('bold')
                    ->url(fn (Job $r) => JobResource::getUrl('view', ['record' => $r]))
                    ->color('primary')
                    ->searchable(['customer_name_snapshot']),
                TextColumn::make('customer_phone_snapshot')->label('Phone')
                    ->url(fn (Job $r) => $r->customer?->quo_conversation_url, shouldOpenInNewTab: true)
                    ->color(fn (Job $r) => filled($r->customer?->quo_conversation_url) ? 'primary' : null)
                    ->placeholder('—')
                    ->searchable(['customer_phone_snapshot']),
                TextColumn::make('technician.name')->label('Tech')->placeholder('—')->toggleable(),
                TextColumn::make('xpro_via')->label('Collected via')->badge()
                    ->state(fn (Job $r) => $r->xproMethodsLabel() ?? '—')
                    ->color('warning')
                    ->placeholder('—'),
                TextColumn::make('amount')->label('Amount')->alignEnd()
                    ->state(fn (Job $r) => Money::format($r->xproOwedCents())),
                TextColumn::make('payment_received_at')->label('Collected on')
                    ->dateTime('M j, Y')->timezone(Timezone::display())->placeholder('—'),
            ])
            ->recordActions([
                $this->markCollectedAction(),
            ])
            ->emptyStateHeading('Nothing owed from Xpro')
            ->emptyStateDescription('Every Xpro-collected payment has been collected back from the team.')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    /** One-click "we've collected this money back from the Xpro team". */
    protected function markCollectedAction(): Action
    {
        return Action::make('markXproCollected')
            ->label('Mark collected')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(fn (Job $record) => 'Collected from Xpro — '.$record->customer_name_snapshot)
            ->modalDescription(fn (Job $record) => 'Mark the '
                .Money::format($record->xproOwedCents())
                .' the Xpro team collected ('.($record->xproMethodsLabel() ?? 'Xpro method')
                .') as collected back from them.')
            ->modalSubmitActionLabel('Yes, mark collected')
            ->action(function (Job $record) {
                app(JobLifecycle::class)->markXproCollected($record, auth()->user());

                Notification::make()->title('Marked collected from Xpro')->success()->send();
            });
    }
}
