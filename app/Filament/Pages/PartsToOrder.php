<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Jobs\Support\JobActions;
use App\Models\Job;
use App\Services\JobLifecycle;
use App\Support\BusinessDays;
use App\Support\Timezone;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * "Parts" — the parts workbench. Tabs:
 *   - Ready to order / Scheduled ahead / All — scheduled jobs whose glass part
 *     hasn't been ordered yet (Job::scopePartsToOrder); Ready = scheduled local
 *     date on/before the next business day (Job::scopePartsReadyToOrder). Jobs
 *     leave once ordered (JobLifecycle::markPartsOrdered).
 *   - Prepay pending — "Other"-supplier parts ordered but not yet prepaid.
 *   - Parts returned — parts a tech picked up before a cancellation and hasn't
 *     brought back yet (Job::scopePartsReturnPending); cleared via
 *     JobLifecycle::markPartReturned.
 */
class PartsToOrder extends Page implements HasTable
{
    use GatedByPermission;
    use InteractsWithTable;

    protected static function requiredPermission(): string
    {
        return 'manage_jobs';
    }

    protected string $view = 'filament.pages.parts-to-order';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Parts';

    protected static ?string $title = 'Parts';

    protected static ?int $navigationSort = 6;

    /** 'ready' | 'ahead' | 'prepay' | 'returned' | 'all'. */
    #[Url]
    public string $view_tab = 'ready';

    public static function getNavigationBadge(): ?string
    {
        // Parts needing a human now: ready to order + "Other" parts ordered but
        // not yet prepaid (a part on hold, unpaid) + parts out awaiting return.
        $count = Job::partsReadyToOrder()->count()
            + Job::prepayPending()->count()
            + Job::partsReturnPending()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tab_ready')->label('Ready to order')
                ->color(fn () => $this->view_tab === 'ready' ? 'primary' : 'gray')
                ->badge(Job::partsReadyToOrder()->count())
                ->action(fn () => $this->switchTab('ready')),
            Action::make('tab_ahead')->label('Scheduled ahead')
                ->color(fn () => $this->view_tab === 'ahead' ? 'primary' : 'gray')
                ->action(fn () => $this->switchTab('ahead')),
            Action::make('tab_prepay')->label('Prepay pending')
                ->color(fn () => $this->view_tab === 'prepay' ? 'primary' : 'gray')
                ->badge(Job::prepayPending()->count())
                ->badgeColor('danger')
                ->action(fn () => $this->switchTab('prepay')),
            Action::make('tab_returned')->label('Parts returned')
                ->color(fn () => $this->view_tab === 'returned' ? 'primary' : 'gray')
                ->badge(Job::partsReturnPending()->count())
                ->badgeColor('warning')
                ->action(fn () => $this->switchTab('returned')),
            Action::make('tab_all')->label('All')
                ->color(fn () => $this->view_tab === 'all' ? 'primary' : 'gray')
                ->action(fn () => $this->switchTab('all')),
        ];
    }

    private function switchTab(string $tab): void
    {
        $this->view_tab = $tab;
        $this->flushCachedTableRecords();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                // Prepay pending is its own set — "Other" parts already ordered but
                // not yet prepaid — not part of the parts-to-order (unordered) list.
                if ($this->view_tab === 'prepay') {
                    return Job::query()->prepayPending();
                }

                // Parts a tech picked up before a cancellation, still out.
                if ($this->view_tab === 'returned') {
                    return Job::query()->partsReturnPending();
                }

                $query = Job::query()->partsToOrder();

                return match ($this->view_tab) {
                    'ready' => $query->where('scheduled_at', '<=', self::readyCutoffUtc()),
                    'ahead' => $query->where('scheduled_at', '>', self::readyCutoffUtc()),
                    default => $query,
                };
            })
            ->defaultSort('scheduled_at', 'asc')
            ->columns([
                TextColumn::make('customer_name_snapshot')->label('Customer')->weight('bold')->searchable(),
                TextColumn::make('entity.code')->label('Loc')->badge(),
                TextColumn::make('part_number')->label('Part #')->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('warehouse.name')->label('Warehouse')
                    ->state(fn (Job $record) => $record->warehouse?->name ?: $record->warehouse_other ?: '— pick —')
                    ->color(fn (Job $record) => $record->warehouse_id || filled($record->warehouse_other) ? null : 'warning')
                    ->visibleFrom('md'),
                TextColumn::make('scheduled_at')->label('Scheduled')
                    ->state(fn (Job $record) => Timezone::toDisplay($record->scheduled_at)?->format('D, M j g:ia'))
                    ->placeholder('—')
                    ->visible(fn () => $this->view_tab !== 'returned'),
                TextColumn::make('ready_status')->label('Status')
                    ->state(fn (Job $record) => self::isReady($record) ? 'Ready to order' : 'Scheduled ahead')
                    ->badge()
                    ->color(fn (Job $record) => self::isReady($record) ? 'warning' : 'gray')
                    ->visible(fn () => $this->view_tab !== 'returned'),
                TextColumn::make('prepay_status')->label('Prepay')
                    ->state(fn (Job $record) => match (true) {
                        ! $record->usesOtherWarehouse() => '—',
                        $record->isPartsPaid() => 'Prepaid',
                        default => 'Prepay due',
                    })
                    ->badge()
                    ->color(fn (Job $record) => match (true) {
                        ! $record->usesOtherWarehouse() => 'gray',
                        $record->isPartsPaid() => 'success',
                        default => 'danger',
                    })
                    ->visible(fn () => $this->view_tab !== 'returned')
                    ->visibleFrom('md'),
                TextColumn::make('part_return_needed_at')->label('Return requested')
                    ->state(fn (Job $record) => Timezone::toDisplay($record->part_return_needed_at)?->format('D, M j g:ia'))
                    ->badge()->color('warning')
                    ->visible(fn () => $this->view_tab === 'returned'),
                TextColumn::make('technician.name')->label('Tech')->placeholder('—')
                    ->visibleFrom('md'),
            ])
            ->recordActions([
                JobActions::orderParts(),
                $this->quickMarkOrderedAction(),
                JobActions::undoPartsOrdered(),
                JobActions::markPartsPaid(),
                JobActions::markPartReturned(),
            ])
            ->emptyStateHeading(fn () => $this->view_tab === 'returned' ? 'No parts out for return' : 'Nothing to order')
            ->emptyStateDescription(fn () => $this->view_tab === 'returned'
                ? 'When a job is canceled and the tech had already picked up the part, it shows here until it\'s returned.'
                : 'Scheduled jobs needing a part show up here until they\'re marked ordered.')
            ->emptyStateIcon(Heroicon::OutlinedShoppingCart);
    }

    private static function isReady(Job $record): bool
    {
        $local = Timezone::toDisplay($record->scheduled_at);

        return $local !== null && BusinessDays::isWithinNextBusinessDay($local);
    }

    private static function readyCutoffUtc(): CarbonInterface
    {
        $cutoffLocal = BusinessDays::nextBusinessDay(Timezone::now());
        [, $cutoffUtc] = Timezone::localDayUtcRange($cutoffLocal);

        return $cutoffUtc;
    }

    /**
     * Quick stamp without opening the order-links modal — but still asks for the
     * part cost, which intake no longer collects.
     */
    private function quickMarkOrderedAction(): Action
    {
        return Action::make('quickMarkOrdered')
            ->label('Mark ordered')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Job $record) => ! $record->isLaborOnly() && ! $record->isPartsOrdered())
            ->modalHeading('Mark this part ordered?')
            ->fillForm(fn (Job $record) => ['parts_cost' => JobActions::partsCostRows($record)])
            ->schema(JobActions::partsCostFields())
            ->modalSubmitActionLabel('Mark ordered')
            ->action(function (Job $record, array $data) {
                JobActions::applyPartsCost($record, $data['parts_cost'] ?? []);
                app(JobLifecycle::class)->markPartsOrdered($record, auth()->user());
                Notification::make()->title('Marked ordered')->success()->send();
            });
    }
}
