<?php

namespace App\Services\Reporting;

use App\Enums\JobStatus;
use App\Models\AdMetric;
use App\Models\Entity;
use App\Models\Job;
use App\Support\Timezone;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Builds the weekly ROAS report by joining two sources on (location, week):
 *
 *   - Google Ads (ad_metrics): spend + conversions ("leads"), bucketed by the
 *     local calendar date the metrics cover.
 *   - Booked jobs: revenue (the quoted amount) + profit (quote minus part cost
 *     minus tech payout — expected, even if not yet collected), attributed to the
 *     week the job was booked (created). Fell-through bookings (lost/canceled)
 *     are excluded.
 *
 * Weeks are Monday-start in the display timezone. Everything is computed in PHP
 * from two flat queries, so it stays portable across SQLite (tests) and MySQL.
 */
class RoasReport
{
    /**
     * @param  int[]|null  $entityIds  Limit to these entities, or null for all active.
     * @return array{
     *     range: array{start: Carbon, end: Carbon},
     *     weeks: array<int, array{key: string, start: Carbon, end: Carbon, label: string}>,
     *     locations: array<int, array{entity: Entity, byWeek: array<string, RoasMetrics>, total: RoasMetrics}>,
     *     grand: RoasMetrics
     * }
     */
    public function build(CarbonInterface $start, CarbonInterface $end, ?array $entityIds = null): array
    {
        $tz = Timezone::display();
        $start = Carbon::parse($start, $tz)->startOfDay();
        $end = Carbon::parse($end, $tz)->endOfDay();

        $weeks = $this->weekBuckets($start, $end);

        $entities = Entity::query()
            ->when($entityIds !== null, fn ($q) => $q->whereIn('id', $entityIds))
            ->when($entityIds === null, fn ($q) => $q->where('active', true))
            ->orderBy('code')
            ->get();

        // Seed an empty RoasMetrics for every (entity, week) so the grid is dense.
        $byEntityWeek = [];
        foreach ($entities as $entity) {
            foreach ($weeks as $week) {
                $byEntityWeek[$entity->id][$week['key']] = new RoasMetrics;
            }
        }

        $this->applyAdSpend($byEntityWeek, $weeks, $entities, $start, $end);
        $this->applyBookedJobs($byEntityWeek, $weeks, $entities, $start, $end);

        // Assemble per-location totals + grand totals.
        $grand = new RoasMetrics;
        $locations = [];

        foreach ($entities as $entity) {
            $total = new RoasMetrics;
            foreach ($weeks as $week) {
                $cell = $byEntityWeek[$entity->id][$week['key']];
                $total->add($cell->spendCents, $cell->leads, $cell->revenueCents, $cell->profitCents);
                $grand->add($cell->spendCents, $cell->leads, $cell->revenueCents, $cell->profitCents);
            }

            $locations[] = [
                'entity' => $entity,
                'byWeek' => $byEntityWeek[$entity->id],
                'total' => $total,
            ];
        }

        return [
            'range' => ['start' => $start, 'end' => $end],
            'weeks' => $weeks,
            'locations' => $locations,
            'grand' => $grand,
        ];
    }

    /** @return array<int, array{key: string, start: Carbon, end: Carbon, label: string}> */
    protected function weekBuckets(Carbon $start, Carbon $end): array
    {
        $weeks = [];
        $cursor = $start->copy()->startOfWeek(CarbonInterface::MONDAY);

        while ($cursor->lessThanOrEqualTo($end)) {
            $weekStart = $cursor->copy();
            $weekEnd = $cursor->copy()->endOfWeek(CarbonInterface::SUNDAY);

            $weeks[] = [
                'key' => $weekStart->format('Y-m-d'),
                'start' => $weekStart,
                'end' => $weekEnd,
                'label' => $this->weekLabel($weekStart, $weekEnd),
            ];

            $cursor->addWeek();
        }

        return $weeks;
    }

    protected function weekLabel(Carbon $start, Carbon $end): string
    {
        return $start->month === $end->month
            ? $start->format('M j').'–'.$end->format('j')
            : $start->format('M j').'–'.$end->format('M j');
    }

    /** Find the Monday-start week key for a local date, or null if out of range. */
    protected function weekKeyFor(Carbon $localDate, array $weeks): ?string
    {
        $key = $localDate->copy()->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d');

        return in_array($key, array_column($weeks, 'key'), true) ? $key : null;
    }

    protected function applyAdSpend(array &$byEntityWeek, array $weeks, Collection $entities, Carbon $start, Carbon $end): void
    {
        $rows = AdMetric::query()
            ->whereIn('entity_id', $entities->pluck('id'))
            ->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->get(['entity_id', 'date', 'cost_micros', 'conversions']);

        foreach ($rows as $row) {
            $localDate = Carbon::parse($row->date, Timezone::display());
            $key = $this->weekKeyFor($localDate, $weeks);
            if ($key === null || ! isset($byEntityWeek[$row->entity_id][$key])) {
                continue;
            }

            $byEntityWeek[$row->entity_id][$key]->add(
                spendCents: intdiv((int) $row->cost_micros, 10_000),
                leads: (float) $row->conversions,
            );
        }
    }

    protected function applyBookedJobs(array &$byEntityWeek, array $weeks, Collection $entities, Carbon $start, Carbon $end): void
    {
        // Booked revenue/profit are attributed to the week the job was booked
        // (created_at). Revenue = quote; profit = quote − part cost − tech pay
        // (expected, even if not yet collected). Lost/canceled bookings excluded.
        $startUtc = $start->copy()->utc();
        $endUtc = $end->copy()->utc();

        $jobs = Job::query()
            ->whereIn('entity_id', $entities->pluck('id'))
            ->whereNotIn('status', [JobStatus::Lost->value, JobStatus::Canceled->value])
            ->whereBetween('created_at', [$startUtc, $endUtc])
            ->get([
                'entity_id', 'created_at',
                'quote_amount_cents', 'part_cost_cents', 'tech_pay_amount_cents',
                'additional_labor_cents',
            ]);

        foreach ($jobs as $job) {
            $localDate = Timezone::toDisplay($job->created_at);
            $key = $this->weekKeyFor($localDate, $weeks);
            if ($key === null || ! isset($byEntityWeek[$job->entity_id][$key])) {
                continue;
            }

            $revenue = (int) ($job->quote_amount_cents ?? 0);
            $profit = $revenue
                - (int) ($job->part_cost_cents ?? 0)
                - (int) ($job->tech_pay_amount_cents ?? 0)
                - (int) ($job->additional_labor_cents ?? 0);

            $byEntityWeek[$job->entity_id][$key]->add(
                revenueCents: $revenue,
                profitCents: $profit,
            );
        }
    }
}
