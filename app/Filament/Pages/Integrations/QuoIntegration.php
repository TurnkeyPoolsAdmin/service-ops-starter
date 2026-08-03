<?php

namespace App\Filament\Pages\Integrations;

use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/** Quo / OpenPhone API credentials (the primary SMS provider). */
class QuoIntegration extends IntegrationSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $title = 'Quo / OpenPhone';

    protected static function settingsMap(): array
    {
        return [
            'openphone_base_url' => ['default' => 'https://api.openphone.com'],
            'openphone_api_key' => ['sensitive' => true],
            'openphone_webhook_secret' => ['sensitive' => true],
        ];
    }

    protected function sections(): array
    {
        return [
            Section::make('OpenPhone / Quo')
                ->description('Stored encrypted at rest. Verify the live API before production sends.')
                ->schema([
                    TextInput::make('openphone_base_url')->label('API base URL'),
                    TextInput::make('openphone_api_key')->label('API key')->password()->revealable(),
                    TextInput::make('openphone_webhook_secret')->label('Webhook signing secret')->password()->revealable(),
                ]),
        ];
    }
}
