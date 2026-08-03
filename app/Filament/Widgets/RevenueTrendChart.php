<?php

namespace App\Filament\Widgets;

use App\Enums\JobStatus;
use App\Models\Job;
use App\Support\Timezone;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Weekly booked revenue vs. estimated profit, by the week the job was created
 * (Pacific) — the same "booked" attribution as the ROAS report
 * (RoasReport::applyBookedJobs): revenue = quote, profit = quote − part cost −
 * tech pay − additional labor, lost/canceled excluded.
 */
class RevenueTrendChart extends ChartWidget
{
    protected static ?int $sort = -25;

    protected ?string $heading = 'Booked revenue';

    protected ?string $description = 'Weekly revenue vs. estimated profit, by week booked - last 12 weeks';

    protected ?string $maxHeight = '270px';

    public static function canView(): bool
    {
        return auth()->user()?->can('manage_jobs') ?? false;
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $series = self::weeklySeries(12);

        return [
            'datasets' => [
                [
                    'label' => 'Revenue',
                    'data' => array_map(fn (array $week) => round($week['revenue'] / 100, 2), $series),
                    'borderColor' => '#159cc4',
                    'backgroundColor' => 'rgba(21, 156, 196, 0.14)',
                    'pointBackgroundColor' => '#159cc4',
                    'fill' => 'start',
                    'tension' => 0.35,
                    'borderWidth' => 2,
                    'pointRadius' => 2.5,
                ],
                [
                    'label' => 'Est. profit',
                    'data' => array_map(fn (array $week) => round($week['profit'] / 100, 2), $series),
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.08)',
                    'pointBackgroundColor' => '#10b981',
                    'fill' => 'start',
                    'tension' => 0.35,
                    'borderWidth' => 2,
                    'pointRadius' => 2.5,
                ],
            ],
            'labels' => array_column($series, 'label'),
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'end',
                        labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, pointStyle: 'circle' },
                    },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ctx.dataset.label + ': $' + Number(ctx.parsed.y).toLocaleString(),
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: (value) => '$' + Number(value).toLocaleString() },
                        grid: { color: 'rgba(148, 163, 184, 0.14)' },
                    },
                    x: { grid: { display: false } },
                },
            }
            JS);
    }

    /**
     * Booked revenue/profit/job-count per Pacific week (Monday start), oldest
     * first, ending with the current week. Cents. Shared with OpsStatsOverview
     * so the sparkline and the chart can never disagree.
     *
     * @return list<array{label: string, revenue: int, profit: int, jobs: int}>
     */
    public static function weeklySeries(int $weeks): array
    {
        $firstWeekStart = Timezone::now()->startOfWeek()->subWeeks($weeks - 1);

        $jobs = Job::query()
            ->whereNotIn('status', [JobStatus::Lost->value, JobStatus::Canceled->value])
            ->where('created_at', '>=', $firstWeekStart->copy()->utc())
            ->get([
                'created_at', 'quote_amount_cents', 'part_cost_cents',
                'tech_pay_amount_cents', 'additional_labor_cents',
            ]);

        $series = [];
        for ($i = 0; $i < $weeks; $i++) {
            $weekStart = $firstWeekStart->copy()->addWeeks($i);
            $series[$weekStart->format('Y-m-d')] = [
                'label' => $weekStart->format('M j'),
                'revenue' => 0,
                'profit' => 0,
                'jobs' => 0,
            ];
        }

        foreach ($jobs as $job) {
            $key = Timezone::toDisplay($job->created_at)->startOfWeek()->format('Y-m-d');
            if (! isset($series[$key])) {
                continue;
            }

            $revenue = (int) ($job->quote_amount_cents ?? 0);
            $series[$key]['revenue'] += $revenue;
            $series[$key]['profit'] += $revenue
                - (int) ($job->part_cost_cents ?? 0)
                - (int) ($job->tech_pay_amount_cents ?? 0)
                - (int) ($job->additional_labor_cents ?? 0);
            $series[$key]['jobs']++;
        }

        return array_values($series);
    }
}
