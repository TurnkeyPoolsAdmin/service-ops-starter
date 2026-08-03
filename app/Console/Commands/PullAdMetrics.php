<?php

namespace App\Console\Commands;

use App\Models\AdMetric;
use App\Models\Entity;
use App\Services\Ads\Providers\AdsInsightsProvider;
use App\Support\Timezone;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Pull daily Google Ads cost/conversion metrics into the ad_metrics table.
 *
 * Scheduled daily (see routes/console.php). By default it re-fetches a trailing
 * window (config glass.google_ads.lookback_days) so late-attributed conversions
 * get corrected. The upsert keyed on (entity_id, date) makes re-runs idempotent.
 *
 * Outside production the bound provider is NullAdsProvider, so this is a no-op
 * there (the dashboard just reads whatever rows already exist).
 */
class PullAdMetrics extends Command
{
    protected $signature = 'ads:pull-metrics
        {--days= : Number of trailing days to fetch (defaults to config lookback)}
        {--start= : Start date YYYY-MM-DD (overrides --days)}
        {--end= : End date YYYY-MM-DD (defaults to today)}
        {--entity= : Limit to a single entity code (e.g. LA)}';

    protected $description = 'Fetch daily Google Ads spend + conversions per location into ad_metrics';

    public function handle(AdsInsightsProvider $provider): int
    {
        $end = $this->option('end')
            ? Carbon::parse($this->option('end'), Timezone::display())
            : Timezone::now();

        if ($this->option('start')) {
            $start = Carbon::parse($this->option('start'), Timezone::display());
        } else {
            $days = (int) ($this->option('days') ?: config('glass.google_ads.lookback_days', 30));
            $start = $end->copy()->subDays(max(0, $days - 1));
        }

        $start = $start->startOfDay();
        $end = $end->startOfDay();

        $entities = Entity::query()
            ->where('active', true)
            ->whereNotNull('google_ads_customer_id')
            ->where('google_ads_customer_id', '!=', '')
            ->when($this->option('entity'), fn ($q) => $q->where('code', $this->option('entity')))
            ->get();

        if ($entities->isEmpty()) {
            $this->warn('No active entities with a Google Ads customer ID. Set one in Admin > Entities.');

            return self::SUCCESS;
        }

        $totalRows = 0;

        foreach ($entities as $entity) {
            $rows = $provider->fetchDailyMetrics($entity->google_ads_customer_id, $start, $end);

            foreach ($rows as $row) {
                AdMetric::query()->updateOrCreate(
                    ['entity_id' => $entity->id, 'date' => $row->date],
                    [
                        'cost_micros' => $row->costMicros,
                        'conversions' => $row->conversions,
                        'clicks' => $row->clicks,
                        'impressions' => $row->impressions,
                        'synced_at' => now(),
                    ],
                );
                $totalRows++;
            }

            $this->line(sprintf('%s (%s): %d day(s)', $entity->code, $entity->google_ads_customer_id, count($rows)));
        }

        $this->info(sprintf(
            'Pulled %d metric row(s) for %s → %s across %d location(s).',
            $totalRows,
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            $entities->count(),
        ));

        return self::SUCCESS;
    }
}
