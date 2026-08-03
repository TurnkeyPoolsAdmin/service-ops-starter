<?php

namespace App\Filament\Pages\Integrations;

use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/** Podium OAuth app + webhook (inbound sales texts, optional outbound). */
class PodiumIntegration extends IntegrationSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $title = 'Podium';

    protected static function settingsMap(): array
    {
        return [
            'podium_client_id' => [],
            'podium_client_secret' => ['sensitive' => true],
            'podium_location_uid' => [],
            'podium_webhook_secret' => ['sensitive' => true],
            'podium_refresh_token' => ['sensitive' => true],
        ];
    }

    protected function integrationActions(): array
    {
        return [
            // Saves the form BEFORE leaving for Podium. As a plain ->url() link
            // this silently discarded whatever was typed, so a freshly pasted
            // client ID never reached the DB and Podium was handed the OLD one —
            // surfacing as "Client ID and redirect URI do not match", which
            // points at the redirect URI and hides the real cause. Cost a real
            // debugging session on 2026-07-21.
            Action::make('connectPodium')
                ->label(filled(Setting::get('podium_refresh_token')) ? 'Reconnect Podium' : 'Connect Podium')
                ->icon(Heroicon::OutlinedLink)
                ->color(filled(Setting::get('podium_refresh_token')) ? 'gray' : 'primary')
                ->action(function () {
                    $this->save();

                    return redirect()->route('podium.oauth.connect');
                }),
        ];
    }

    protected function sections(): array
    {
        return [
            Section::make('Podium')
                ->description('Captures the sales team\'s texts AND (optionally) sends outgoing texts through the same Podium number. Add the OAuth client ID/secret below, Save, then click "Connect Podium" (top right) and log in with your normal Podium account — that discovers your location and registers the webhook automatically.')
                ->schema([
                    Placeholder::make('podium_status')
                        ->label('Connection')
                        ->content(fn () => $this->podiumStatus()),
                    TextInput::make('podium_client_id')->label('OAuth client ID'),
                    TextInput::make('podium_client_secret')->label('OAuth client secret')->password()->revealable(),
                    TextInput::make('podium_location_uid')
                        ->label('Location UID')
                        ->helperText('Auto-filled by "Connect Podium". Required to send via Podium.'),
                    TextInput::make('podium_webhook_secret')
                        ->label('Webhook signing secret')
                        ->password()
                        ->revealable()
                        ->helperText('Auto-generated and registered on connect. Stored encrypted at rest.'),
                    TextInput::make('podium_refresh_token')
                        ->label('OAuth refresh token')
                        ->password()
                        ->revealable()
                        ->helperText('Set automatically on connect; rotates itself. No need to paste manually.'),
                ]),
        ];
    }

    private function podiumStatus(): HtmlString
    {
        $connected = filled(Setting::get('podium_refresh_token'));
        $loc = Setting::get('podium_location_uid');

        $status = $connected
            ? '<span style="color:#16a34a;font-weight:600;">● Connected</span>'.($loc ? ' — location '.e($loc) : '')
            : '<span style="color:#9ca3af;font-weight:600;">○ Not connected</span> — add the client ID/secret, Save, then click Connect Podium.';

        return new HtmlString(
            $status
            .'<div style="font-size:0.75rem;color:#6b7280;margin-top:0.35rem;">Redirect URI to register on your Podium OAuth app:<br><code>'.e(route('podium.oauth.callback')).'</code></div>'
            .'<div style="font-size:0.75rem;color:#6b7280;margin-top:0.25rem;">Webhook URL: <code>'.e(route('webhooks.podium')).'</code></div>'
        );
    }
}
