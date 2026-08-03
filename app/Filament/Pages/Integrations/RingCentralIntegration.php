<?php

namespace App\Filament\Pages\Integrations;

use App\Models\Setting;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/** RingCentral server-to-server (JWT) SMS credentials + webhook token. */
class RingCentralIntegration extends IntegrationSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static ?string $title = 'RingCentral';

    protected static function settingsMap(): array
    {
        return [
            'ringcentral_server_url' => ['default' => 'https://platform.ringcentral.com'],
            'ringcentral_client_id' => [],
            'ringcentral_client_secret' => ['sensitive' => true],
            'ringcentral_jwt' => ['sensitive' => true],
            'ringcentral_from_e164' => [],
            'ringcentral_webhook_token' => ['sensitive' => true],
        ];
    }

    protected function sections(): array
    {
        return [
            Section::make('RingCentral')
                ->description('Send and receive texts from a RingCentral number. Uses the server-to-server (JWT) auth flow: create an app with the "SMS" and "Webhook Subscriptions" scopes in the RingCentral developer console, generate a JWT credential, and paste the values below. Stored encrypted at rest.')
                ->schema([
                    Placeholder::make('ringcentral_status')
                        ->label('Connection')
                        ->content(fn () => $this->ringCentralStatus()),
                    TextInput::make('ringcentral_server_url')
                        ->label('Server URL')
                        ->placeholder('https://platform.ringcentral.com')
                        ->helperText('Production: https://platform.ringcentral.com · Sandbox: https://platform.devtest.ringcentral.com'),
                    TextInput::make('ringcentral_client_id')->label('App client ID'),
                    TextInput::make('ringcentral_client_secret')->label('App client secret')->password()->revealable(),
                    TextInput::make('ringcentral_jwt')
                        ->label('JWT credential')
                        ->password()
                        ->revealable()
                        ->helperText('The long-lived JWT generated for this app/user in the RingCentral console.'),
                    TextInput::make('ringcentral_from_e164')
                        ->label('Sending number (E.164)')
                        ->placeholder('+18185550000')
                        ->helperText('An SMS-enabled number on this RingCentral account. Falls back to the global sending number if blank.'),
                    TextInput::make('ringcentral_webhook_token')
                        ->label('Webhook verification token')
                        ->password()
                        ->revealable()
                        ->helperText('A secret string of your choosing. Set the SAME value as the subscription\'s verificationToken when you register the webhook, so we can authenticate inbound notifications.'),
                ]),
        ];
    }

    private function ringCentralStatus(): HtmlString
    {
        $configured = filled(Setting::get('ringcentral_client_id'))
            && filled(Setting::get('ringcentral_client_secret'))
            && filled(Setting::get('ringcentral_jwt'));

        $status = $configured
            ? '<span style="color:#16a34a;font-weight:600;">● Credentials set</span> — pick "RingCentral" on the Sending status page to send through it.'
            : '<span style="color:#9ca3af;font-weight:600;">○ Not configured</span> — add the client ID/secret and JWT, then Save.';

        return new HtmlString(
            $status
            .'<div style="font-size:0.75rem;color:#6b7280;margin-top:0.35rem;">Webhook URL to register on your RingCentral SMS subscription:<br><code>'.e(route('webhooks.ringcentral')).'</code></div>'
            .'<div style="font-size:0.75rem;color:#6b7280;margin-top:0.25rem;">Event filter: <code>/restapi/v1.0/account/~/extension/~/message-store/instant?type=SMS</code></div>'
        );
    }
}
