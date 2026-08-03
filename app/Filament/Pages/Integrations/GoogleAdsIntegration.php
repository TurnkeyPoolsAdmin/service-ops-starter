<?php

namespace App\Filament\Pages\Integrations;

use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/** Google Ads MCC credentials behind the daily ads:pull-metrics / ROAS dashboard. */
class GoogleAdsIntegration extends IntegrationSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $title = 'Google Ads';

    protected static function settingsMap(): array
    {
        return [
            'google_ads_developer_token' => ['sensitive' => true],
            'google_ads_client_id' => [],
            'google_ads_client_secret' => ['sensitive' => true],
            'google_ads_refresh_token' => ['sensitive' => true],
            'google_ads_login_customer_id' => [],
        ];
    }

    protected function sections(): array
    {
        return [
            Section::make('Google Ads (ROAS dashboard)')
                ->description('Used by the daily ads:pull-metrics command. Per-location account IDs are set on each Entity. Real pulls require ADS_PROVIDER=google_ads in production.')
                ->schema([
                    TextInput::make('google_ads_developer_token')->label('Developer token')->password()->revealable(),
                    TextInput::make('google_ads_client_id')->label('OAuth client ID'),
                    TextInput::make('google_ads_client_secret')->label('OAuth client secret')->password()->revealable(),
                    TextInput::make('google_ads_refresh_token')->label('OAuth refresh token')->password()->revealable(),
                    TextInput::make('google_ads_login_customer_id')
                        ->label('Manager (MCC) customer ID')
                        ->placeholder('1234567890')
                        ->helperText('Digits only, no dashes. Leave blank if accounts are not under a manager account.'),
                ]),
        ];
    }
}
