<?php

namespace App\Http\Controllers;

use App\Filament\Pages\Integrations\PodiumIntegration;
use App\Models\Setting;
use App\Services\Podium\PodiumApi;
use App\Services\Podium\PodiumOAuth;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Drives the Podium OAuth consent handshake from Admin → Integrations. The owner
 * authorizes this app against their *business* Podium account (their normal
 * login), which yields a refresh token and unlocks their location — neither of
 * which the developer-portal access alone can see. On return we discover the
 * location UID and register the message webhook automatically.
 */
class PodiumOAuthController extends Controller
{
    public function connect(Request $request, PodiumOAuth $oauth): RedirectResponse
    {
        abort_unless($request->user()?->can('manage_settings'), 403);

        $state = Str::random(40);
        $request->session()->put('podium_oauth_state', $state);

        $url = $oauth->authorizeUrl(route('podium.oauth.callback'), $state);
        if ($url === null) {
            Notification::make()->title('Add the Podium OAuth client ID first, then save.')->warning()->send();

            return redirect(PodiumIntegration::getUrl());
        }

        return redirect()->away($url);
    }

    public function callback(Request $request, PodiumOAuth $oauth, PodiumApi $api): RedirectResponse
    {
        abort_unless($request->user()?->can('manage_settings'), 403);

        $expected = $request->session()->pull('podium_oauth_state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            Notification::make()->title('Podium connection failed: invalid state.')->danger()->send();

            return redirect(PodiumIntegration::getUrl());
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            Notification::make()->title('Podium connection was cancelled or denied.')->warning()->send();

            return redirect(PodiumIntegration::getUrl());
        }

        if (! $oauth->exchangeAuthorizationCode((string) $request->query('code'), route('podium.oauth.callback'))) {
            Notification::make()->title('Podium connection failed: could not exchange the code.')->danger()->send();

            return redirect(PodiumIntegration::getUrl());
        }

        // Discover the location and register the webhook so no manual step remains.
        $this->discoverLocationAndRegisterWebhook($api);

        Notification::make()
            ->title('Podium connected')
            ->body($this->summary())
            ->success()
            ->send();

        return redirect(PodiumIntegration::getUrl());
    }

    private function discoverLocationAndRegisterWebhook(PodiumApi $api): void
    {
        $locations = $api->listLocations();
        $location = $locations[0] ?? null;

        $locationUid = $location['uid'] ?? null;
        if (blank($locationUid)) {
            return;
        }

        Setting::put('podium_location_uid', $locationUid, sensitive: false);

        // Ensure a signing secret exists, then register the webhook for it.
        $secret = Setting::get('podium_webhook_secret');
        if (blank($secret)) {
            $secret = Str::random(48);
            Setting::put('podium_webhook_secret', $secret, sensitive: true);
        }

        $api->createMessageWebhook(route('webhooks.podium'), $secret, $locationUid);
    }

    private function summary(): string
    {
        $loc = Setting::get('podium_location_uid');

        return $loc
            ? "Location {$loc} linked and the message webhook was registered automatically."
            : 'Authorized, but no location was returned — check the OAuth scopes (needs read_locations).';
    }
}
