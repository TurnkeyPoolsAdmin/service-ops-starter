<?php

namespace App\Filament\Pages\Integrations;

use App\Models\Setting;
use App\Services\Intake\SalesIntakeService;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Admin > Integrations > Sales dashboard — the receiving end of the sales team's
 * auto-handoff (POST /webhooks/sales-intake).
 *
 * Two settings, and the second one is the important one:
 *  - sales_intake_secret: the shared HMAC secret. The SAME string must be saved
 *    on the sales dashboard as `dispatch.intake_secret`. Encrypted at rest.
 *  - sales_auto_intake_enabled: OFF by default. Off, a pushed lead lands as an
 *    ordinary job and waits on the Send-welcome screen for a human. On, we check
 *    Podium ourselves and send the welcome the dispatcher would have chosen.
 */
class SalesDashboardIntegration extends IntegrationSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $title = 'Sales dashboard (auto-intake)';

    protected static function settingsMap(): array
    {
        return [
            'sales_intake_secret' => ['sensitive' => true],
            'sales_auto_intake_enabled' => ['type' => 'bool', 'default' => false],
        ];
    }

    protected function sections(): array
    {
        return [
            Section::make('Sales dashboard (auto-intake)')
                ->description('The sales team\'s dashboard pushes a lead here the moment it is booked, and we create the job automatically. Give the sales side the endpoint URL below plus the same secret, then decide whether the welcome text goes out on its own.')
                ->schema([
                    Placeholder::make('sales_intake_status')
                        ->label('Status')
                        ->content(fn () => $this->status()),
                    Placeholder::make('sales_intake_url')
                        ->label('Endpoint URL')
                        ->content(fn () => new HtmlString(
                            '<code>'.e(url('/webhooks/sales-intake')).'</code>'
                            .'<br><span style="font-size:.8rem;color:#6b7280;">Save this on the sales dashboard as <code>dispatch.intake_url</code>.</span>'
                        )),
                    TextInput::make('sales_intake_secret')
                        ->label('Shared signing secret')
                        ->password()
                        ->revealable()
                        ->helperText('Any long random string. Paste the identical value into the sales dashboard as dispatch.intake_secret. Requests are signed HMAC-SHA256 over "{timestamp}.{body}" and rejected after 5 minutes. Stored encrypted at rest.'),
                    Toggle::make('sales_auto_intake_enabled')
                        ->label('Send the welcome automatically')
                        ->helperText('OFF (recommended to start): a pushed lead waits on the Send-welcome screen, exactly like a manual lead, and the Telegram alert links you straight there. ON: we look for a Podium conversation with the customer and send the same welcome you would have picked - the Podium handoff plus the dispatch intro when a conversation exists, the dispatch intro alone when it does not. Test leads never text either way.'),
                ]),
        ];
    }

    private function status(): HtmlString
    {
        $configured = filled(Setting::get('sales_intake_secret'));
        $auto = SalesIntakeService::autoIntakeEnabled();

        if (! $configured) {
            return new HtmlString(
                '<span style="color:#9ca3af;font-weight:600;">○ Not configured</span> — set a signing secret and Save. Until then the endpoint refuses every push in production.'
            );
        }

        $mode = $auto
            ? '<span style="color:#16a34a;font-weight:600;">● Connected, welcome automatic</span> — pushed leads text the customer without a dispatcher.'
            : '<span style="color:#2563eb;font-weight:600;">● Connected, welcome manual</span> — pushed leads wait on the Send-welcome screen.';

        return new HtmlString($mode);
    }
}
