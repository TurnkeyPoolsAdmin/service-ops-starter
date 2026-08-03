<?php

namespace App\Services\Ads\Providers;

use Carbon\CarbonInterface;

/**
 * Default provider for dev/test and any non-production environment: fetches
 * nothing, so the app never hits the Google Ads API outside production. The
 * ROAS dashboard simply shows whatever ad_metrics rows already exist (e.g.
 * seeded/imported), or zeroes.
 */
class NullAdsProvider implements AdsInsightsProvider
{
    public function fetchDailyMetrics(string $customerId, CarbonInterface $start, CarbonInterface $end): array
    {
        return [];
    }
}
