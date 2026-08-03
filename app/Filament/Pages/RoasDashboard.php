<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatedByPermission;
use App\Models\AdMetric;
use App\Models\Entity;
use App\Services\Reporting\RoasReport;
use App\Support\Timezone;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use UnitEnum;

/**
 * Owner-facing ROAS scorecard: weekly Google Ads spend + leads (conversions)
 * vs. booked revenue/profit, per location. Super-admin only — dispatch and
 * regular admin users never see financials. Reached via the Admin >
 * Analytics hub, not its own sidebar item.
 */
class RoasDashboard extends Page
{
    use GatedByPermission;

    protected static function requiredPermission(): string
    {
        return 'view_roas';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected string $view = 'filament.pages.roas-dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $title = 'ROAS Dashboard';

    protected static ?int $navigationSort = -10;

    public ?array $data = [];

    /**
     * "Refresh now" — pull the latest Google Ads spend/leads for the currently
     * selected date range, on demand, instead of waiting for the 6 AM cron. The
     * pull is synchronous (only a handful of REST calls — one per location), so
     * the page re-renders with fresh numbers as soon as it returns. Outside
     * production the bound provider is NullAdsProvider, so this is a safe no-op.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh from Google Ads')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function () {
                    [$start, $end] = $this->range();

                    Artisan::call('ads:pull-metrics', [
                        '--start' => $start->format('Y-m-d'),
                        '--end' => $end->format('Y-m-d'),
                    ]);

                    Notification::make()
                        ->title('ROAS data refreshed')
                        ->body(trim(Artisan::output()) ?: 'No rows returned. Check ADS_PROVIDER and credentials.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /** Most recent ad_metrics sync, formatted for display, or null if never. */
    public function lastSyncedAt(): ?string
    {
        $ts = AdMetric::max('synced_at');

        return $ts
            ? Carbon::parse($ts)->timezone(Timezone::display())->format('M j, Y g:i A T')
            : null;
    }

    public function mount(): void
    {
        $now = Timezone::now();

        $this->form->fill([
            'preset' => '7d',
            'start' => $now->copy()->subDays(6)->format('Y-m-d'),
            'end' => $now->format('Y-m-d'),
            'entity_ids' => [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)->schema([
                    Select::make('preset')
                        ->label('Date range')
                        ->options([
                            'today' => 'Today',
                            'yesterday' => 'Yesterday',
                            '7d' => 'Last 7 days',
                            '30d' => 'Last 30 days',
                            'custom' => 'Custom range…',
                        ])
                        ->default('7d')
                        ->selectablePlaceholder(false)
                        ->live(),
                    Select::make('entity_ids')
                        ->label('Locations')
                        ->multiple()
                        ->placeholder('All locations')
                        ->options(fn () => Entity::query()->orderBy('code')->pluck('name', 'id'))
                        ->live(),
                ]),
                Grid::make(2)
                    ->visible(fn (Get $get) => $get('preset') === 'custom')
                    ->schema([
                        DatePicker::make('start')
                            ->label('From')
                            ->native(false)
                            ->live()
                            ->required()
                            // No future dates, and never after the chosen end.
                            ->maxDate(fn (Get $get) => $get('end') ?: Timezone::now()),
                        DatePicker::make('end')
                            ->label('To')
                            ->native(false)
                            ->live()
                            ->required()
                            ->minDate(fn (Get $get) => $get('start') ?: null)
                            ->maxDate(fn () => Timezone::now()),
                    ]),
            ])
            ->statePath('data');
    }

    /** Selected [start, end] from the filter, normalized (start ≤ end). */
    protected function range(): array
    {
        $tz = Timezone::display();
        $now = Timezone::now();

        return match ($this->data['preset'] ?? '7d') {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            '30d' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'custom' => $this->customRange($tz, $now),
            default => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    /** Custom [start, end] from the From/To pickers, normalized (start ≤ end). */
    protected function customRange(string $tz, Carbon $now): array
    {
        $start = Carbon::parse($this->data['start'] ?? $now->copy()->subDays(6), $tz);
        $end = Carbon::parse($this->data['end'] ?? $now, $tz);

        return $end->lessThan($start) ? [$end, $start] : [$start, $end];
    }

    /** Build the report from the current filter state. */
    public function report(): array
    {
        [$start, $end] = $this->range();

        $entityIds = array_values(array_filter($this->data['entity_ids'] ?? []));

        return app(RoasReport::class)->build(
            $start,
            $end,
            $entityIds === [] ? null : array_map('intval', $entityIds),
        );
    }
}
