<?php

namespace App\Services\Ads\Providers;

use Carbon\CarbonInterface;

interface AdsInsightsProvider
{
    /**
     * Fetch daily cost/conversion metrics for a single Google Ads account over
     * an inclusive [start, end] date range.
     *
     * Implementations must NOT throw for provider-level / API errors — return an
     * empty array (and log) instead, so a single bad account can't abort the
     * whole pull. $customerId is the 10-digit ID without dashes.
     *
     * @return DailyAdMetric[]
     */
    public function fetchDailyMetrics(string $customerId, CarbonInterface $start, CarbonInterface $end): array;
}
