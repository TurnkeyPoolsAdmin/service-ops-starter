<?php

namespace App\Filament\Pages;

use App\Enums\JobStatus;
use App\Filament\Concerns\GatedByPermission;
use App\Models\Employee;
use App\Models\Entity;
use App\Support\Money;
use App\Support\Timezone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Tech Scoreboard — one row per technician over a rolling window (today,
 * yesterday, last 7 or last 30 days), pairing the tech's FIXED data (their
 * assignment priority + the flat per-job rate they charge) against what they
 * actually did and earned in the window: jobs completed, total labor paid, and
 * the average paid per job.
 *
 * "Total paid" is LABOR ONLY — tech_pay_amount_cents + additional_labor_cents —
 * so the average-per-job column is comparable to the fixed cost per job, and a
 * gap between the two is exactly the bonus/extra labor we're looking for.
 * Parts the tech fronted (tech_parts_cost_cents) are deliberately excluded:
 * that's a reimbursement passing through them, not pay. Warranty extra pay
 * rides its own settlement (never the job) and is likewise out of scope here.
 *
 * Reached via the Admin > Analytics hub, not its own sidebar item; gated on
 * view_roas, which RoleSeeder grants to no role, so only a super_admin gets in
 * (via the Gate::before bypass) — same posture as the ROAS Dashboard.
 */
class TechScoreboard extends Page implements HasTable
{
    use GatedByPermission;
    use InteractsWithTable;

    protected static function requiredPermission(): string
    {
        return 'view_roas';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected string $view = 'filament.pages.tech-scoreboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $title = 'Tech Scoreboard';

    protected static ?string $navigationLabel = 'Tech Scoreboard';

    protected static ?int $navigationSort = -9;

    /** Selected window. #[Url] so a view can be linked/bookmarked. */
    #[Url]
    public string $range = 'today';

    /** key => [label, days back from today] — 0 days = today only. */
    public const RANGES = [
        'today' => ['Today', 0],
        'yesterday' => ['Yesterday', 0],
        'last_7' => ['Last 7 days', 6],
        'last_30' => ['Last 30 days', 29],
    ];

    public function mount(): void
    {
        if (! array_key_exists($this->range, self::RANGES)) {
            $this->range = 'today';
        }
    }

    public function getSubheading(): ?string
    {
        [$start, $end] = $this->localRange();

        $label = $start->isSameDay($end)
            ? $start->format('l, F j, Y')
            : $start->format('M j').' – '.$end->format('M j, Y');

        return $label.' — jobs completed and labor paid per technician.';
    }

    /** The selected window as local (Pacific) start/end dates. */
    protected function localRange(): array
    {
        $today = Timezone::now()->startOfDay();

        if ($this->range === 'yesterday') {
            $day = $today->copy()->subDay();

            return [$day, $day->copy()];
        }

        $back = self::RANGES[$this->range][1] ?? 0;

        return [$today->copy()->subDays($back), $today->copy()];
    }

    /** The same window as [startUtc, endUtc] bounds for querying timestamps. */
    protected function utcRange(): array
    {
        [$start, $end] = $this->localRange();

        return [Timezone::localDayUtcRange($start)[0], Timezone::localDayUtcRange($end)[1]];
    }

    /** One button per window; the active one is highlighted. */
    protected function getHeaderActions(): array
    {
        return collect(self::RANGES)
            ->map(fn (array $range, string $key) => Action::make('range_'.$key)
                ->label($range[0])
                ->color($this->range === $key ? 'primary' : 'gray')
                ->action(function () use ($key) {
                    $this->range = $key;
                    $this->flushCachedTableRecords();
                }))
            ->values()
            ->all();
    }

    public function table(Table $table): Table
    {
        // Closure query, not an eager builder, for the same reason as the
        // End-of-Day summary: Filament builds the Table once per request, before
        // a header action mutates $this->range, so the window has to be read at
        // query time or every click would render the window you just left.
        $labor = DB::raw('tech_pay_amount_cents + COALESCE(additional_labor_cents, 0)');

        return $table
            ->query(function () use ($labor): Builder {
                [$start, $end] = $this->utcRange();

                $completed = fn (Builder $q) => $q->whereNotNull('work_completed_at')
                    ->whereNotIn('status', [JobStatus::Canceled->value, JobStatus::Lost->value])
                    ->whereBetween('work_completed_at', [$start, $end]);

                return Employee::query()
                    ->whereHas('jobsAsTechnician', $completed)
                    ->withCount(['jobsAsTechnician as window_jobs_count' => $completed])
                    ->withSum(['jobsAsTechnician as window_paid_cents' => $completed], $labor);
            })
            ->defaultSort('window_paid_cents', 'desc')
            ->paginated(false)
            ->persistFiltersInSession()
            ->filters([
                SelectFilter::make('pool')
                    ->label('Location')
                    ->options(fn () => collect(self::poolMap())->map(fn (array $pool) => $pool['label'])->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereIn('entity_id', self::poolMap()[$data['value']]['ids'] ?? [])
                        : $query),
            ])
            ->columns([
                TextColumn::make('name')->label('Technician')->weight('bold')->searchable()->sortable(),
                TextColumn::make('priority')->label('Priority')
                    ->state(fn (Employee $r) => $r->priorityLabel() ?? 'Unranked')
                    ->badge()->color(fn (Employee $r) => $r->priority === null ? 'gray' : 'info')
                    ->sortable()->alignCenter(),
                TextColumn::make('fixed_cost_cents')->label('Fixed cost / job')
                    ->state(fn (Employee $r) => $r->fixed_cost_cents === null ? '—' : Money::format((int) $r->fixed_cost_cents))
                    ->sortable()->alignEnd(),
                TextColumn::make('window_jobs_count')->label('Jobs completed')
                    ->sortable()->alignCenter(),
                TextColumn::make('window_paid_cents')->label('Total paid')
                    ->state(fn (Employee $r) => Money::format(self::paidCents($r)))
                    ->sortable()->alignEnd(),
                TextColumn::make('paid_per_job')->label('Paid / job')
                    ->state(fn (Employee $r) => Money::format(self::paidPerJobCents($r)))
                    // Flag anyone averaging above their flat rate — that gap is a bonus.
                    ->color(fn (Employee $r) => self::isAboveFixedCost($r) ? 'warning' : 'gray')
                    ->alignEnd(),
                TextColumn::make('entity.code')->label('Loc')->badge()->placeholder('—')->toggleable(),
            ])
            ->emptyStateHeading('No completed jobs in this window')
            ->emptyStateDescription('Pick a longer window above. Techs appear here once they have jobs marked complete in the selected period.')
            ->emptyStateIcon(Heroicon::OutlinedTrophy);
    }

    /**
     * The Location filter's options, keyed by a stable slug. Techs are grouped by
     * TECH POOL, not by entity — SFV and AGLA share one crew, so they're one
     * option ("SFV / AGLA"); Del Mar has its own. Mirrors Entity::techPoolLabel/
     * techPoolEntityIds, the same pairing the tech pickers use, so a scoreboard
     * view matches who you'd actually reach for at that location.
     *
     * Filtering keys off the TECH's home entity (employees.entity_id), not the
     * job's, so "Del Mar" means the Del Mar crew's numbers even on the odd job
     * they cover elsewhere. A tech with no entity set appears only unfiltered.
     *
     * @return array<string, array{label: string, ids: array<int>}>
     */
    private static function poolMap(): array
    {
        $pools = [];

        foreach (Entity::query()->orderBy('name')->get() as $entity) {
            $label = $entity->techPoolLabel();
            $key = str($label)->slug('_')->value();

            $pools[$key] ??= ['label' => $label, 'ids' => $entity->techPoolEntityIds()];
        }

        return $pools;
    }

    private static function paidCents(Employee $r): int
    {
        return (int) ($r->window_paid_cents ?? 0);
    }

    /** Average labor paid per completed job in the window (0 when no jobs). */
    private static function paidPerJobCents(Employee $r): int
    {
        $jobs = (int) ($r->window_jobs_count ?? 0);

        return $jobs > 0 ? intdiv(self::paidCents($r), $jobs) : 0;
    }

    /** True when the tech averaged more than their flat rate (i.e. earned extra). */
    private static function isAboveFixedCost(Employee $r): bool
    {
        return $r->fixed_cost_cents !== null
            && self::paidPerJobCents($r) > (int) $r->fixed_cost_cents;
    }
}
