<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\IsTileHub;
use App\Filament\Pages\Integrations\AiAssistIntegration;
use App\Filament\Pages\Integrations\EmailIntegration;
use App\Filament\Pages\Integrations\GoogleAdsIntegration;
use App\Filament\Pages\Integrations\PlaidIntegration;
use App\Filament\Pages\Integrations\PodiumIntegration;
use App\Filament\Pages\Integrations\QuoIntegration;
use App\Filament\Pages\Integrations\RingCentralIntegration;
use App\Filament\Pages\Integrations\SalesDashboardIntegration;
use App\Filament\Pages\Integrations\SendingStatus;
use App\Filament\Pages\Integrations\StripeIntegration;
use App\Filament\Pages\Integrations\TelegramIntegration;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Admin > Settings > Integrations hub. Was one long page of stacked sections;
 * now a tile per integration (Quo, Podium, RingCentral, Stripe, …), each its own
 * settings screen under App\Filament\Pages\Integrations. Keeps this class name
 * and URL because the Podium/Plaid OAuth controllers redirect back to it.
 */
class ManageIntegrations extends Page
{
    use IsTileHub;

    protected string $view = 'filament.pages.tile-hub';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $title = 'Integrations & API Keys';

    protected static ?int $navigationSort = 10;

    // Reached via the Admin > Settings hub, not its own sidebar item.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static function tileClasses(): array
    {
        return [
            ['class' => SendingStatus::class, 'label' => 'Sending status', 'icon' => Heroicon::OutlinedSignal],
            ['class' => QuoIntegration::class, 'label' => 'Quo / OpenPhone', 'icon' => Heroicon::OutlinedChatBubbleLeftRight],
            ['class' => PodiumIntegration::class, 'label' => 'Podium', 'icon' => Heroicon::OutlinedInboxArrowDown],
            ['class' => RingCentralIntegration::class, 'label' => 'RingCentral', 'icon' => Heroicon::OutlinedPhone],
            ['class' => StripeIntegration::class, 'label' => 'Stripe', 'icon' => Heroicon::OutlinedCreditCard],
            ['class' => PlaidIntegration::class, 'label' => 'Plaid (bank)', 'icon' => Heroicon::OutlinedBuildingLibrary],
            ['class' => GoogleAdsIntegration::class, 'label' => 'Google Ads', 'icon' => Heroicon::OutlinedChartBar],
            ['class' => SalesDashboardIntegration::class, 'label' => 'Sales dashboard', 'icon' => Heroicon::OutlinedInboxArrowDown],
            ['class' => TelegramIntegration::class, 'label' => 'Telegram alerts', 'icon' => Heroicon::OutlinedBellAlert],
            ['class' => EmailIntegration::class, 'label' => 'Email / Resend', 'icon' => Heroicon::OutlinedEnvelope],
            ['class' => AiAssistIntegration::class, 'label' => 'AI assist', 'icon' => Heroicon::OutlinedSparkles],
        ];
    }
}
