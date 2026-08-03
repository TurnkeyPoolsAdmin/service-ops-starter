<?php

use App\Models\AdMetric;
use App\Models\Entity;
use App\Services\Ads\Providers\AdsInsightsProvider;
use App\Services\Ads\Providers\DailyAdMetric;
use App\Services\Ads\Providers\NullAdsProvider;
use Carbon\CarbonInterface;
use Database\Seeders\EntitySeeder;

beforeEach(function () {
    $this->seed([EntitySeeder::class]);
    $this->la = Entity::where('code', 'LA')->first();
    $this->la->update(['google_ads_customer_id' => '1234567890']);
});

/** A provider that returns a fixed set of days, recording how often it was called. */
function fakeProvider(array $days, ?callable $onCall = null): AdsInsightsProvider
{
    return new class($days, $onCall) implements AdsInsightsProvider
    {
        public function __construct(public array $days, public $onCall) {}

        public function fetchDailyMetrics(string $customerId, CarbonInterface $start, CarbonInterface $end): array
        {
            if ($this->onCall) {
                ($this->onCall)($customerId, $start, $end);
            }

            return $this->days;
        }
    };
}

it('upserts one ad_metrics row per day and is idempotent across re-runs', function () {
    $this->app->instance(AdsInsightsProvider::class, fakeProvider([
        new DailyAdMetric('2026-06-15', 100_000_000, 4, 10, 100),
        new DailyAdMetric('2026-06-16', 50_000_000, 1, 5, 50),
    ]));

    $this->artisan('ads:pull-metrics', ['--start' => '2026-06-15', '--end' => '2026-06-16'])
        ->assertSuccessful();

    expect(AdMetric::count())->toBe(2);
    $row = AdMetric::whereDate('date', '2026-06-15')->first();
    expect($row->cost_micros)->toBe(100_000_000)
        ->and((float) $row->conversions)->toBe(4.0)
        ->and($row->cost_cents)->toBe(10000);   // 100,000,000 micros / 10,000

    // Re-run with corrected (lagging) conversions for the same days.
    $this->app->instance(AdsInsightsProvider::class, fakeProvider([
        new DailyAdMetric('2026-06-15', 100_000_000, 6, 10, 100),   // conversions corrected up
        new DailyAdMetric('2026-06-16', 50_000_000, 1, 5, 50),
    ]));

    $this->artisan('ads:pull-metrics', ['--start' => '2026-06-15', '--end' => '2026-06-16'])
        ->assertSuccessful();

    expect(AdMetric::count())->toBe(2);   // still 2 rows, not 4
    expect((float) AdMetric::whereDate('date', '2026-06-15')->first()->conversions)->toBe(6.0);
});

it('skips entities without a Google Ads customer ID', function () {
    // SFV / DelMar have no customer ID; LA does. Provider should only be asked for LA.
    $calledFor = [];
    $this->app->instance(AdsInsightsProvider::class, fakeProvider(
        [new DailyAdMetric('2026-06-15', 1_000_000, 1)],
        function (string $customerId) use (&$calledFor) {
            $calledFor[] = $customerId;
        },
    ));

    $this->artisan('ads:pull-metrics', ['--start' => '2026-06-15', '--end' => '2026-06-15'])
        ->assertSuccessful();

    expect($calledFor)->toBe(['1234567890'])
        ->and(AdMetric::where('entity_id', $this->la->id)->count())->toBe(1);
});

it('binds NullAdsProvider outside production', function () {
    // Default test env is not production, so the real provider is never used.
    expect(app(AdsInsightsProvider::class))->toBeInstanceOf(NullAdsProvider::class);
});
