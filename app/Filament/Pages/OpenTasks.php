<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Jobs\JobResource;
use App\Filament\Resources\Jobs\Support\JobActions;
use App\Filament\Resources\ReviewRequests\ReviewRequestResource;
use App\Models\Job;
use App\Support\Money;
use App\Support\Timezone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Cross-cutting "what still needs a human" queue — every job with at least one
 * open task, self-fixing as each condition clears (nothing here is stamped;
 * every tab re-derives its set live off Job::openTaskTypes() each request).
 * Task types: needs scheduling, needs completion (2h+ past the arrival
 * window), missing payment, part not ordered, part not paid (Other supplier),
 * calibration needed, review call needed. A job can carry more than one at
 * once — the "All" tab shows every affected job with a badge per open task.
 */
class OpenTasks extends Page implements HasTable
{
    use GatedByPermission;
    use InteractsWithTable;

    protected static function requiredPermission(): string
    {
        return 'manage_jobs';
    }

    protected string $view = 'filament.pages.open-tasks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Open Tasks';

    protected static ?string $title = 'Open Tasks';

    protected static ?int $navigationSort = 1;

    private const TASKS = [
        'scheduling' => ['label' => 'Needs scheduling', 'color' => 'warning'],
        'completion' => ['label' => 'Needs completion', 'color' => 'danger'],
        'payment' => ['label' => 'Missing payment', 'color' => 'danger'],
        'part_ordered' => ['label' => 'Part not ordered', 'color' => 'warning'],
        'part_paid' => ['label' => 'Part not paid', 'color' => 'danger'],
        'calibration' => ['label' => 'Calibration needed', 'color' => 'info'],
        'review_call' => ['label' => 'Review call needed', 'color' => 'warning'],
    ];

    /** 'all' | one of the TASKS keys. */
    #[Url]
    public string $view_tab = 'all';

    public static function getNavigationBadge(): ?string
    {
        $count = count(static::allOpenTaskIds());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->tabAction('all', 'All', count(static::allOpenTaskIds())),
            $this->tabAction('scheduling', self::TASKS['scheduling']['label'], Job::query()->needsScheduling()->count()),
            $this->tabAction('completion', self::TASKS['completion']['label'], count(static::overdueCompletionIds())),
            $this->tabAction('payment', self::TASKS['payment']['label'], Job::query()->awaitingPayment()->count()),
            $this->tabAction('part_ordered', self::TASKS['part_ordered']['label'], Job::query()->partsToOrder()->count()),
            $this->tabAction('part_paid', self::TASKS['part_paid']['label'], Job::query()->prepayPending()->count()),
            $this->tabAction('calibration', self::TASKS['calibration']['label'], static::calibrationOpenQuery()->count()),
            $this->tabAction('review_call', self::TASKS['review_call']['label'], Job::query()->needsReviewCall()->count()),
        ];
    }

    private function tabAction(string $key, string $label, int $count): Action
    {
        return Action::make('tab_'.$key)
            ->label($label)
            ->color(fn () => $this->view_tab === $key ? 'primary' : 'gray')
            ->badge($count > 0 ? $count : null)
            ->action(function () use ($key) {
                $this->view_tab = $key;
                $this->resetTable();
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->tableQuery()->with('reviewRequest'))
            ->defaultSort('scheduled_at')
            ->columns([
                TextColumn::make('job_number')->label('Job')->searchable()->weight('bold'),
                TextColumn::make('customer_name_snapshot')->label('Name')->searchable(),
                TextColumn::make('city')->label('City')->placeholder('—')->limit(13),
                TextColumn::make('open_tasks')
                    ->label('Open task')
                    ->state(fn (Job $record) => collect($record->openTaskTypes())
                        ->map(fn (string $type) => self::TASKS[$type]['label'])
                        ->all())
                    ->badge()
                    ->color(fn ($state) => collect(self::TASKS)->firstWhere('label', $state)['color'] ?? 'gray'),
                TextColumn::make('requested_daytime_raw')
                    ->label('Requested date and time')
                    ->placeholder('—')
                    ->visible(fn () => in_array($this->view_tab, ['all', 'scheduling'], true)),
                TextColumn::make('scheduled_at')
                    ->label('Scheduled')
                    ->state(fn (Job $record) => Timezone::toDisplay($record->scheduled_at)?->format('D, M j g:ia'))
                    ->placeholder('—')
                    ->visible(fn () => $this->view_tab !== 'scheduling'),
                TextColumn::make('balance_due')
                    ->label('Balance due')
                    ->state(fn (Job $record) => $record->balanceDueCents() > 0 ? Money::format($record->balanceDueCents()) : '—')
                    ->visible(fn () => in_array($this->view_tab, ['all', 'payment'], true))
                    ->visibleFrom('md'),
                TextColumn::make('warehouse')
                    ->label('Warehouse')
                    ->state(fn (Job $record) => $record->warehouse?->name ?: $record->warehouse_other ?: '—')
                    ->visible(fn () => in_array($this->view_tab, ['all', 'part_ordered', 'part_paid'], true))
                    ->visibleFrom('md'),
                TextColumn::make('technician.name')->label('Tech')->placeholder('—')
                    ->visibleFrom('md'),
            ])
            ->recordActions([
                JobActions::schedule(),
                JobActions::completeFirstInstallDay(),
                JobActions::markWorkComplete(),
                JobActions::orderParts(),
                JobActions::markPartsPaid(),
                $this->reviewsAction(),
                $this->openJobAction(),
            ])
            ->emptyStateHeading('No open tasks')
            ->emptyStateDescription('Every job is scheduled, completed, paid, ordered, prepaid, and calibration-confirmed.')
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentCheck);
    }

    private function openJobAction(): Action
    {
        return Action::make('openJob')
            ->label('Open')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (Job $record) => JobResource::getUrl('view', ['record' => $record]));
    }

    /** Jumps to the Reviews dashboard to make (or log) the first call. */
    private function reviewsAction(): Action
    {
        return Action::make('goToReviews')
            ->label('Review call')
            ->icon(Heroicon::OutlinedPhone)
            ->color('warning')
            ->visible(fn (Job $record) => $record->needsReviewCall())
            ->url(fn () => ReviewRequestResource::getUrl('index'));
    }

    private function tableQuery(): Builder
    {
        return match ($this->view_tab) {
            'scheduling' => Job::query()->needsScheduling(),
            'completion' => Job::query()->whereIn('id', static::overdueCompletionIds()),
            'payment' => Job::query()->awaitingPayment(),
            'part_ordered' => Job::query()->partsToOrder(),
            'part_paid' => Job::query()->prepayPending(),
            'calibration' => static::calibrationOpenQuery(),
            'review_call' => Job::query()->needsReviewCall(),
            default => Job::query()->whereIn('id', static::allOpenTaskIds()),
        };
    }

    private static function calibrationOpenQuery(): Builder
    {
        return Job::query()->whereNotNull('calibration_status')->whereNull('calibration_confirmed_at');
    }

    /** Distinct ids of every job carrying at least one open task, across all types. */
    private static function allOpenTaskIds(): array
    {
        return collect()
            ->merge(Job::query()->needsScheduling()->pluck('id'))
            ->merge(static::overdueCompletionIds())
            ->merge(Job::query()->awaitingPayment()->pluck('id'))
            ->merge(Job::query()->partsToOrder()->pluck('id'))
            ->merge(Job::query()->prepayPending()->pluck('id'))
            ->merge(static::calibrationOpenQuery()->pluck('id'))
            ->merge(Job::query()->needsReviewCall()->pluck('id'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The window-end check is per-job (exact-time / custom hours), so this
     * coarse-filters in SQL then applies Job::isOverdueForCompletion() in PHP —
     * same split as OverdueCompletionAlert::dueJobs(), minus its alerted_at gate.
     */
    private static function overdueCompletionIds(): Collection
    {
        $grace = (int) config('glass.telegram.overdue_grace_hours', 2);

        return Job::scheduled()
            ->whereNull('work_completed_at')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now()->subHours($grace))
            ->get()
            ->filter(fn (Job $job) => $job->isOverdueForCompletion())
            ->pluck('id');
    }
}
