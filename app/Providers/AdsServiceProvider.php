<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\Ads\Providers\AdsInsightsProvider;
use App\Services\Ads\Providers\GoogleAdsProvider;
use App\Services\Ads\Providers\NullAdsProvider;
use Illuminate\Support\ServiceProvider;

class AdsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AdsInsightsProvider::class, function ($app) {
            $configured = config('glass.ads_provider', 'null');

            // The real provider is ONLY used in production with explicit opt-in,
            // mirroring the SMS provider gate — no live Google Ads calls in dev/test.
            if ($configured === 'google_ads' && $app->environment('production')) {
                return new GoogleAdsProvider(
                    developerToken: Setting::get('google_ads_developer_token'),
                    clientId: Setting::get('google_ads_client_id'),
                    clientSecret: Setting::get('google_ads_client_secret'),
                    refreshToken: Setting::get('google_ads_refresh_token'),
                    loginCustomerId: Setting::get('google_ads_login_customer_id'),
                    apiBase: config('glass.google_ads.api_base'),
                    apiVersion: config('glass.google_ads.api_version'),
                );
            }

            return new NullAdsProvider;
        });
    }
}
