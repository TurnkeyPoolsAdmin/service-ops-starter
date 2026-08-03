<?php

namespace App\Services\Ads\Providers;

/**
 * Provider-agnostic value object for one day of ad metrics for one account.
 * cost_micros is the Google-native unit (1,000,000 micros = 1 currency unit).
 */
class DailyAdMetric
{
    public function __construct(
        public string $date,          // 'Y-m-d' in the account's own timezone
        public int $costMicros,
        public float $conversions,
        public int $clicks = 0,
        public int $impressions = 0,
    ) {}
}
