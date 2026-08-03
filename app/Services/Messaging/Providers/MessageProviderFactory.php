<?php

namespace App\Services\Messaging\Providers;

use App\Models\Setting;
use App\Services\Podium\PodiumOAuth;
use App\Services\RingCentral\RingCentralOAuth;
use Illuminate\Contracts\Foundation\Application;

/**
 * Resolves the concrete outbound SMS provider. Two safety gates ALWAYS win,
 * regardless of which provider is requested:
 *
 *  1. Real providers only ever send in production — never dev/test.
 *  2. The global kill switch (Admin → Integrations → "Pause outgoing texts")
 *     forces everything to the LogMessageProvider.
 *
 * Past those gates, an explicit per-message provider name (openphone|podium|
 * ringcentral) wins over the runtime override (Setting `outgoing_provider`), which in turn
 * wins over the env default (config `glass.message_provider`). This lets the
 * welcome handoff send one text via Podium and the next via Quo in the same
 * job, while build-mode pause still neutralizes both.
 */
class MessageProviderFactory
{
    public function __construct(private Application $app) {}

    public function make(?string $provider = null): MessageProvider
    {
        if (! $this->app->environment('production')) {
            return new LogMessageProvider;
        }

        if (Setting::get('outgoing_sms_paused') === '1') {
            return new LogMessageProvider;
        }

        $provider = $provider
            ?: (Setting::get('outgoing_provider') ?: config('glass.message_provider', 'log'));

        return match ($provider) {
            'openphone' => new OpenPhoneProvider(
                apiKey: Setting::get('openphone_api_key'),
                baseUrl: Setting::get('openphone_base_url', 'https://api.openphone.com'),
            ),
            'podium' => new PodiumProvider(
                oauth: $this->app->make(PodiumOAuth::class),
                locationUid: Setting::get('podium_location_uid'),
                apiBase: config('glass.podium.api_base'),
                version: config('glass.podium.version'),
            ),
            'ringcentral' => new RingCentralProvider(
                oauth: $this->app->make(RingCentralOAuth::class),
                fromOverride: Setting::get('ringcentral_from_e164'),
            ),
            default => new LogMessageProvider,
        };
    }
}
