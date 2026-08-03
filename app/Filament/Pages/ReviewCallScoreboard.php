<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatedByPermission;
use App\Models\Employee;
use App\Models\ReviewRequest;
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
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Review Call Scoreboard — one row per dispatch team member over a rolling
 * window (today, yesterday, last 7 or last 30 days), showing how many review
 * calls they logged — FIRST calls (ReviewRequest::first_call_logged_by, right
 * after completion) and SECOND calls (ReviewRequest::call_logged_by, the
 * post-text-2 gate) both count toward the total; a single job can contribute
 * one of each; that's correct, two calls happened. Mirrors TechScoreboard's
 * range-button pattern.
 *
 * "Not interested" is derived from cancel_reason rather than a dedicated
 * column — logCall() always stamps call_outcome=connected for a connected
 * call, then cancel()s the drip with reason "not interested (said on call)"
 * when dispatch picks that option, so the reason string is the only signal.
 * That breakdown (Connected/Voicemail/Not interested) is second-call-specific
 * — the first call has its own, simpler outcome set (connected/no answer).
 *
 * Reached via the Admin > Analytics hub, not its own sidebar item; gated on
 * view_roas (granted to no role), so only a super_admin gets in via the
 * Gate::before bypass — same posture as the Tech Scoreboard / ROAS Dashboard.
 */
class ReviewCallScoreboard extends Page implements HasTable
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

    protected string $view = 'filament.pages.review-call-scoreboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $title = 'Review Call Scoreboard';

    protected static ?string $navigationLabel = 'Review Call Scoreboard';

    protected static ?int $navigationSort = -8;

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

        return $label.' — review-drip calls logged per dispatch team member.';
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
        // Closure query, not an eager builder — Filament builds the Table once
        // per request, before a header action mutates $this->range, so the
        // window has to be read at query time or every click would render the
        // window you just left (same reason as TechScoreboard/End-of-Day).
        return $table
            ->query(function (): Builder {
                [$start, $end] = $this->utcRange();

                $inWindow = fn (Builder $q) => $q->whereBetween('call_logged_at', [$start, $end]);
                $inWindowFirst = fn (Builder $q) => $q->whereBetween('first_call_logged_at', [$start, $end]);
                $connected = fn (Builder $q) => $inWindow($q)->where('call_outcome', ReviewRequest::OUTCOME_CONNECTED);
                $voicemail = fn (Builder $q) => $inWindow($q)->where('call_outcome', ReviewRequest::OUTCOME_VOICEMAIL);
                $notInterested = fn (Builder $q) => $inWindow($q)->where('cancel_reason', 'like', 'not interested%');

                return Employee::query()
                    ->where(fn (Builder $q) => $q->whereHas('reviewCallsLogged', $inWindow)
                        ->orWhereHas('firstReviewCallsLogged', $inWindowFirst))
                    ->withCount(['reviewCallsLogged as window_calls_count' => $inWindow])
                    ->withCount(['firstReviewCallsLogged as window_first_calls_count' => $inWindowFirst])
                    ->withCount(['reviewCallsLogged as window_connected_count' => $connected])
                    ->withCount(['reviewCallsLogged as window_voicemail_count' => $voicemail])
                    ->withCount(['reviewCallsLogged as window_not_interested_count' => $notInterested]);
            })
            ->defaultSort('window_calls_count', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Team member')->weight('bold')->searchable()->sortable(),
                TextColumn::make('window_total_calls_count')
                    ->label('Total calls')
                    ->state(fn ($record) => $record->window_first_calls_count + $record->window_calls_count)
                    ->alignCenter()->weight('bold'),
                TextColumn::make('window_first_calls_count')->label('First calls')
                    ->sortable()->alignCenter(),
                TextColumn::make('window_calls_count')->label('Second calls')
                    ->sortable()->alignCenter(),
                TextColumn::make('window_connected_count')->label('Connected')
                    ->sortable()->alignCenter()->color('success'),
                TextColumn::make('window_voicemail_count')->label('Voicemail')
                    ->sortable()->alignCenter()->color('gray'),
                TextColumn::make('window_not_interested_count')->label('Not interested')
                    ->sortable()->alignCenter()->color('warning'),
            ])
            ->emptyStateHeading('No review calls logged in this window')
            ->emptyStateDescription('Pick a longer window above. Team members appear here once they log a review call in the selected period.')
            ->emptyStateIcon(Heroicon::OutlinedPhone);
    }
}
