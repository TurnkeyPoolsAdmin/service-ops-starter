<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatedByPermission;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\WarrantyClaim;
use App\Support\Timezone;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Scheduling calendar: every scheduled job laid out by appointment day so the
 * team can see load at a glance and slot future work. Google-Calendar-style
 * week/day time grid + a month overview, filterable by entity group (SFV/AGLA
 * combined; everything else on its own) and technician. Dispatch + admin.
 */
class JobCalendar extends Page
{
    use GatedByPermission;

    protected static function requiredPermission(): string
    {
        return 'manage_jobs';
    }

    protected string $view = 'filament.pages.job-calendar';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Calendar';

    protected static ?int $navigationSort = 2;

    /** Entity codes that share the LA-area territory and filter together. */
    private const SFV_AGLA_CODES = ['LA', 'SFV'];

    /** Pixel height of one hour row in the week/day time grid. */
    public const HOUR_HEIGHT = 48;

    /** Assumed appointment length (minutes) for sizing/overlap, since jobs have no end time. */
    private const SLOT_MINUTES = 60;

    public ?array $data = [];

    /** Anchor date (Y-m-d) for the current view. */
    public string $anchor = '';

    /** 'week', 'day', or 'month'. */
    public string $mode = 'week';

    /** Per-render cache for the grouped job query (the grid reads it many times). */
    private ?Collection $jobsByDateCache = null;

    /** Per-render cache for the grouped warranty-visit query. */
    private ?Collection $warrantyByDateCache = null;

    public function mount(): void
    {
        $this->anchor = Timezone::now()->format('Y-m-d');
        $this->form->fill([
            'entity_group' => null,
            'technician_id' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)->schema([
                    Select::make('entity_group')
                        ->label('Entity')
                        ->placeholder('All entities')
                        ->options(fn () => $this->entityGroupOptions())
                        ->live(),
                    Select::make('technician_id')
                        ->label('Technician')
                        ->placeholder('All technicians')
                        ->options(fn () => Employee::active()->withJobFunction('technician')
                            ->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->live(),
                ]),
            ])
            ->statePath('data');
    }

    // ---- Header navigation ----

    protected function getHeaderActions(): array
    {
        $viewButton = fn (string $mode, string $label) => Action::make($mode.'View')
            ->label($label)
            ->color(fn () => $this->mode === $mode ? 'primary' : 'gray')
            ->action(fn () => $this->mode = $mode);

        return [
            Action::make('prev')->label('‹')->color('gray')->action(fn () => $this->step(-1)),
            Action::make('today')->label('Today')->color('gray')->action(function () {
                $this->anchor = Timezone::now()->format('Y-m-d');
            }),
            Action::make('next')->label('›')->color('gray')->action(fn () => $this->step(1)),
            $viewButton('day', 'Day'),
            $viewButton('week', 'Week'),
            $viewButton('month', 'Month'),
        ];
    }

    /** Move the anchor by one unit of the current view. */
    private function step(int $direction): void
    {
        $date = Carbon::parse($this->anchor, Timezone::display());
        $date = match ($this->mode) {
            'month' => $date->addMonths($direction),
            'week' => $date->addWeeks($direction),
            default => $date->addDays($direction),
        };
        $this->anchor = $date->format('Y-m-d');
    }

    /** Click a day (month grid or week header) to drill into its day view. */
    public function openDay(string $date): void
    {
        $this->anchor = $date;
        $this->mode = 'day';
    }

    // ---- Shared data ----

    /** Heading for the current view, e.g. "June 2026" or "Jun 22 – 28, 2026". */
    public function heading(): string
    {
        $date = Carbon::parse($this->anchor, Timezone::display());

        if ($this->mode === 'month') {
            return $date->format('F Y');
        }

        if ($this->mode === 'day') {
            return $date->format('l, M j, Y');
        }

        [$start, $end] = $this->weekBounds();
        $sameMonth = $start->month === $end->month;

        return $sameMonth
            ? $start->format('M j').' – '.$end->format('j, Y')
            : $start->format('M j').' – '.$end->format('M j, Y');
    }

    /**
     * Scheduled jobs for the visible range, grouped by local Y-m-d date and
     * ordered by appointment time within each day.
     *
     * @return Collection<string, Collection<int, Job>>
     */
    public function jobsByDate(): Collection
    {
        if ($this->jobsByDateCache !== null) {
            return $this->jobsByDateCache;
        }

        [$start, $end] = $this->visibleRange();

        $jobs = Job::query()
            ->with(['entity', 'technician', 'customer'])
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$start->copy()->utc(), $end->copy()->utc()])
            ->when(filled($this->data['technician_id'] ?? null),
                fn ($q) => $q->where('technician_id', $this->data['technician_id']))
            ->when($this->selectedEntityIds() !== null,
                fn ($q) => $q->whereIn('entity_id', $this->selectedEntityIds()))
            ->orderBy('scheduled_at')
            ->get();

        return $this->jobsByDateCache = $jobs
            ->groupBy(fn (Job $job) => Timezone::toDisplay($job->scheduled_at)->format('Y-m-d'));
    }

    /**
     * Scheduled warranty return visits for the visible range, grouped like
     * jobsByDate(). Rendered in their own hue so a free-of-charge comeback is
     * never mistaken for a revenue job.
     *
     * @return Collection<string, Collection<int, WarrantyClaim>>
     */
    public function warrantyByDate(): Collection
    {
        if ($this->warrantyByDateCache !== null) {
            return $this->warrantyByDateCache;
        }

        [$start, $end] = $this->visibleRange();

        $claims = WarrantyClaim::query()
            ->with(['job.customer', 'entity', 'technician'])
            ->where('status', WarrantyClaim::STATUS_SCHEDULED)
            ->whereBetween('scheduled_at', [$start->copy()->utc(), $end->copy()->utc()])
            ->when(filled($this->data['technician_id'] ?? null),
                fn ($q) => $q->where('technician_id', $this->data['technician_id']))
            ->when($this->selectedEntityIds() !== null,
                fn ($q) => $q->whereIn('entity_id', $this->selectedEntityIds()))
            ->orderBy('scheduled_at')
            ->get();

        return $this->warrantyByDateCache = $claims
            ->groupBy(fn (WarrantyClaim $claim) => Timezone::toDisplay($claim->scheduled_at)->format('Y-m-d'));
    }

    /**
     * Every calendar item for one day — jobs and warranty visits — as
     * ['kind' => 'job'|'warranty', 'model' => Job|WarrantyClaim, 'at' => local
     * Carbon], sorted by time. The single source both the month cells and the
     * time-grid lanes render from.
     */
    public function itemsFor(string $dateKey): Collection
    {
        $jobs = ($this->jobsByDate()[$dateKey] ?? collect())
            ->map(fn (Job $job) => [
                'kind' => 'job', 'model' => $job, 'at' => Timezone::toDisplay($job->scheduled_at),
                'minutes' => $this->durationMinutes($job),
            ]);

        $visits = ($this->warrantyByDate()[$dateKey] ?? collect())
            ->map(fn (WarrantyClaim $claim) => [
                'kind' => 'warranty', 'model' => $claim, 'at' => Timezone::toDisplay($claim->scheduled_at),
                'minutes' => $this->durationMinutes($claim),
            ]);

        return $jobs->concat($visits)->sortBy('at')->values();
    }

    /**
     * The full booked window for an appointment, in minutes: the customer-facing
     * arrival window (default 2h, or the job's override) so an 8–11am booking
     * blocks the whole 8–11 span. Exact-time appointments fall back to one slot.
     */
    private function durationMinutes(Job|WarrantyClaim $model): int
    {
        if ($model->scheduled_exact_time) {
            return self::SLOT_MINUTES;
        }

        $hours = $model->scheduled_window_hours ?: (int) config('glass.appointment_window_hours', 2);

        return max(self::SLOT_MINUTES, $hours * 60);
    }

    /** [startLocal, endLocal] covering the rendered range. */
    private function visibleRange(): array
    {
        $date = Carbon::parse($this->anchor, Timezone::display());

        return match ($this->mode) {
            'day' => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
            'week' => $this->weekBounds(),
            default => [
                $date->copy()->startOfMonth()->startOfWeek(CarbonInterface::MONDAY),
                $date->copy()->endOfMonth()->endOfWeek(CarbonInterface::SUNDAY),
            ],
        };
    }

    /** Mon–Sat bounds of the week containing the anchor (Sunday is never booked). */
    private function weekBounds(): array
    {
        $date = Carbon::parse($this->anchor, Timezone::display());
        $start = $date->copy()->startOfWeek(CarbonInterface::MONDAY);

        return [$start, $start->copy()->addDays(5)->endOfDay()];
    }

    public function todayKey(): string
    {
        return Timezone::now()->format('Y-m-d');
    }

    // ---- Week / day time-grid layout ----

    /**
     * Days rendered in the time grid: 7 for week, 1 for day. Each entry carries
     * the Carbon date, its Y-m-d key, and whether it's today.
     *
     * @return array<int, array{date: Carbon, key: string, isToday: bool}>
     */
    public function gridDays(): array
    {
        $today = $this->todayKey();

        if ($this->mode === 'day') {
            $d = Carbon::parse($this->anchor, Timezone::display());

            return [['date' => $d, 'key' => $d->format('Y-m-d'), 'isToday' => $d->format('Y-m-d') === $today]];
        }

        [$start] = $this->weekBounds();

        return array_map(function (int $i) use ($start, $today) {
            $d = $start->copy()->addDays($i);

            return ['date' => $d, 'key' => $d->format('Y-m-d'), 'isToday' => $d->format('Y-m-d') === $today];
        }, range(0, 5));
    }

    /**
     * First and last hour to show in the grid. Defaults to an 8am–7pm business
     * window, widened to fit any appointment (and its full booked window) that
     * falls outside it.
     *
     * @return array{0: int, 1: int}
     */
    public function hourRange(): array
    {
        $min = 8;
        $max = 19;

        foreach ([$this->jobsByDate(), $this->warrantyByDate()] as $grouped) {
            foreach ($grouped as $items) {
                foreach ($items as $item) {
                    $start = Timezone::toDisplay($item->scheduled_at);
                    $endMin = $start->hour * 60 + $start->minute + $this->durationMinutes($item);
                    $min = min($min, $start->hour);
                    $max = max($max, (int) ceil($endMin / 60));
                }
            }
        }

        return [max(0, $min), min(24, max($max, $min + 1))];
    }

    /**
     * Positioned events for one day — jobs AND warranty visits — with overlap
     * resolved into side-by-side lanes (like Google Calendar). top/height are
     * pixels from the grid top.
     *
     * @return array<int, array{kind: string, model: Job|WarrantyClaim, top: int, height: int, leftPct: float, widthPct: float, time: string}>
     */
    public function dayEvents(string $dateKey): array
    {
        [$startHour] = $this->hourRange();
        $items = $this->itemsFor($dateKey);
        if ($items->isEmpty()) {
            return [];
        }

        // Greedy lane assignment: an event reuses the first lane whose previous
        // event has already ended (assuming a fixed SLOT_MINUTES length).
        $laneEnds = [];   // lane index => end minute-of-day
        $assigned = [];   // item index => lane index

        foreach ($items as $i => $item) {
            $start = $item['at'];
            $startMin = $start->hour * 60 + $start->minute;
            $endMin = $startMin + $item['minutes'];

            $lane = null;
            foreach ($laneEnds as $l => $ends) {
                if ($startMin >= $ends) {
                    $lane = $l;
                    break;
                }
            }
            if ($lane === null) {
                $lane = count($laneEnds);
            }
            $laneEnds[$lane] = $endMin;
            $assigned[$i] = $lane;
        }

        $lanes = max(count($laneEnds), 1);
        $events = [];

        foreach ($items as $i => $item) {
            $start = $item['at'];
            $offsetMin = ($start->hour * 60 + $start->minute) - ($startHour * 60);

            $events[] = [
                'kind' => $item['kind'],
                'model' => $item['model'],
                'top' => (int) round($offsetMin / 60 * self::HOUR_HEIGHT),
                'height' => max(18, (int) round($item['minutes'] / 60 * self::HOUR_HEIGHT) - 2),
                'leftPct' => $assigned[$i] / $lanes * 100,
                'widthPct' => 100 / $lanes,
                'time' => $start->format('g:ia'),
                'endTime' => $start->copy()->addMinutes($item['minutes'])->format('g:ia'),
            ];
        }

        return $events;
    }

    // ---- Month overview ----

    /**
     * Weeks for the month grid: a list of 6-day (Mon–Sat) arrays of Carbon
     * dates. Sunday is skipped entirely — the shop never books it.
     *
     * @return array<int, array<int, Carbon>>
     */
    public function monthWeeks(): array
    {
        [$start, $end] = $this->visibleRange();
        $weeks = [];
        $cursor = $start->copy();

        while ($cursor->lessThanOrEqualTo($end)) {
            $week = [];
            for ($i = 0; $i < 6; $i++) {   // Mon–Sat
                $week[] = $cursor->copy();
                $cursor->addDay();
            }
            $cursor->addDay();             // skip Sunday
            $weeks[] = $week;
        }

        return $weeks;
    }

    public function anchorMonth(): int
    {
        return Carbon::parse($this->anchor, Timezone::display())->month;
    }

    // ---- Mobile agenda (phone-width list view, same underlying data as the grid) ----

    /**
     * The visible range as a flat, chronologically-ordered, day-grouped list —
     * the phone-friendly alternative to the grid. Days with no items are
     * skipped in week/month mode; day mode always includes the (possibly
     * empty) single day so the view can show an explicit empty state.
     *
     * @return array<int, array{date: Carbon, key: string, isToday: bool, items: Collection}>
     */
    public function agendaDays(): array
    {
        [$start, $end] = $this->visibleRange();
        $today = $this->todayKey();

        $days = [];
        $cursor = $start->copy()->startOfDay();
        $lastDay = $end->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $key = $cursor->format('Y-m-d');
            $items = $this->itemsFor($key);

            if ($this->mode === 'day' || $items->isNotEmpty()) {
                $days[] = [
                    'date' => $cursor->copy(),
                    'key' => $key,
                    'isToday' => $key === $today,
                    'items' => $items,
                ];
            }

            $cursor->addDay();
        }

        return $days;
    }

    // ---- Entity grouping (SFV/AGLA combined; everything else individual) ----

    /** @return array<string, string> filter key => label */
    private function entityGroupOptions(): array
    {
        $entities = Entity::query()->orderBy('code')->get();
        $options = [];

        $laArea = $entities->whereIn('code', self::SFV_AGLA_CODES);
        if ($laArea->isNotEmpty()) {
            $options['sfv_agla'] = 'SFV / AGLA';
        }

        foreach ($entities->whereNotIn('code', self::SFV_AGLA_CODES) as $entity) {
            $options['entity_'.$entity->id] = $entity->name;
        }

        return $options;
    }

    /** Resolve the selected filter key to entity ids, or null for "all". */
    private function selectedEntityIds(): ?array
    {
        $key = $this->data['entity_group'] ?? null;
        if (blank($key)) {
            return null;
        }

        if ($key === 'sfv_agla') {
            return Entity::whereIn('code', self::SFV_AGLA_CODES)->pluck('id')->all();
        }

        return [(int) str_replace('entity_', '', $key)];
    }
}
