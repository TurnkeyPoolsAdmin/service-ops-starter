<?php

namespace App\Filament\Widgets;

use App\Models\Job;
use App\Models\ReviewRequest;
use App\Support\Money;
use App\Support\Timezone;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The dashboard's headline KPI row: money booked this week, today's schedule,
 * money still owed, and review calls waiting on dispatch. The `go-stat--*`
 * classes drive the per-card accent color in glass-ops.css.
 */
class OpsStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -30;

    public static function canView(): bool
    {
        return auth()->user()?->can('manage_jobs') ?? false;
    }

    protected function getStats(): array
    {
        // Booked = created this Pacific week, lost/canceled excluded — the same
        // semantics as RoasReport::applyBookedJobs / RevenueTrendChart.
        $weekly = RevenueTrendChart::weeklySeries(8);
        $thisWeek = end($weekly);
        $sparkline = array_map(fn (array $week) => round($week['revenue'] / 100), $weekly);

        $scheduledToday = Job::scheduledToday()->count();
        $scheduledTomorrow = Job::scheduledTomorrow()->count();

        $awaiting = Job::awaitingPayment();
        $awaitingCount = (clone $awaiting)->count();
        $outstandingCents = (int) (clone $awaiting)->selectRaw(
            'coalesce(sum(case when coalesce(quote_amount_cents, 0) > coalesce(amount_collected_cents, 0)'
            .' then coalesce(quote_amount_cents, 0) - coalesce(amount_collected_cents, 0) else 0 end), 0) as cents'
        )->value('cents');

        $reviewCalls = ReviewRequest::where('status', ReviewRequest::STATUS_AWAITING_CALL)->count();

        // "Failed SMS" deliberately omitted — see OutboxStatsOverview. Failures
        // are alerted per-occurrence over Telegram, not tallied here.
        return [
            Stat::make('Booked this week', Money::format($thisWeek['revenue']))
                ->description($thisWeek['jobs'].' '.str('job')->plural($thisWeek['jobs']).' since '.Timezone::now()->startOfWeek()->format('D, M j'))
                ->icon('heroicon-m-banknotes')
                ->chart($sparkline)
                ->color('primary')
                ->extraAttributes(['class' => 'go-stat go-stat--sky']),

            Stat::make('Scheduled today', $scheduledToday)
                ->description($scheduledTomorrow.' tomorrow')
                ->icon('heroicon-m-calendar-days')
                ->color('info')
                ->extraAttributes(['class' => 'go-stat go-stat--mint']),

            Stat::make('Awaiting payment', Money::format($outstandingCents))
                ->description($awaitingCount.' '.str('job')->plural($awaitingCount).' with work done')
                ->icon('heroicon-m-credit-card')
                ->color('warning')
                ->extraAttributes(['class' => 'go-stat go-stat--amber']),

            Stat::make('Review calls waiting', $reviewCalls)
                ->description($reviewCalls > 0 ? 'Call before the 7pm cutoff' : 'All caught up')
                ->icon('heroicon-m-phone-arrow-up-right')
                ->color($reviewCalls > 0 ? 'danger' : 'success')
                ->extraAttributes(['class' => 'go-stat '.($reviewCalls > 0 ? 'go-stat--rose' : 'go-stat--mint')]),
        ];
    }
}
