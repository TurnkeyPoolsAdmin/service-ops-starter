<?php

namespace App\Filament\Pages\Integrations;

use App\Models\PlaidItem;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/** Plaid credentials + the Chase link flow feeding the Bank Transactions ledger. */
class PlaidIntegration extends IntegrationSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $title = 'Plaid (bank transactions)';

    protected static function settingsMap(): array
    {
        return [
            'plaid_client_id' => [],
            'plaid_secret' => ['sensitive' => true],
            'plaid_environment' => ['default' => 'sandbox'],
        ];
    }

    protected function integrationActions(): array
    {
        return [
            // Saves first for the same reason as Connect Podium — a plain link
            // would discard freshly pasted credentials before Plaid needs them.
            Action::make('connectChase')
                ->label(PlaidItem::query()->exists() ? 'Connect another bank' : 'Connect Chase')
                ->icon(Heroicon::OutlinedBuildingLibrary)
                ->color(PlaidItem::query()->exists() ? 'gray' : 'primary')
                ->action(function () {
                    $this->save();

                    return redirect()->route('plaid.link.connect');
                }),
        ];
    }

    protected function sections(): array
    {
        return [
            Section::make('Plaid (bank transactions)')
                ->description('Pulls Chase checking + credit-card transactions daily into the Bank Transactions ledger. Add the client ID/secret from your Plaid dashboard, pick the environment, Save, then click "Connect Chase" (top right) and log into Chase. Stored encrypted at rest.')
                ->schema([
                    Placeholder::make('plaid_status')
                        ->label('Connection')
                        ->content(fn () => $this->plaidStatus()),
                    TextInput::make('plaid_client_id')->label('Client ID'),
                    TextInput::make('plaid_secret')->label('Secret')->password()->revealable(),
                    Select::make('plaid_environment')
                        ->label('Environment')
                        ->options([
                            'sandbox' => 'Sandbox (test banks, free)',
                            'production' => 'Production (real banks)',
                        ])
                        ->native(false)
                        ->selectablePlaceholder(false)
                        ->helperText('Start in Sandbox to test the flow at $0, switch to Production once approved.'),
                ]),
        ];
    }

    private function plaidStatus(): HtmlString
    {
        $items = PlaidItem::query()->orderBy('institution_name')->get();
        $configured = filled(Setting::get('plaid_client_id')) && filled(Setting::get('plaid_secret'));

        if ($items->isNotEmpty()) {
            $status = $items->map(function (PlaidItem $item) {
                $when = $item->last_synced_at?->diffForHumans() ?? 'not synced yet';
                $label = e($item->institution_name ?: 'Bank');
                $dot = $item->status === 'active' ? '#16a34a' : '#dc2626';

                return '<span style="color:'.$dot.';font-weight:600;">●</span> '.$label.' — last sync '.e($when);
            })->implode('<br>');
        } elseif ($configured) {
            $status = '<span style="color:#9ca3af;font-weight:600;">○ Credentials set</span> — click Connect Chase (top right) and log in.';
        } else {
            $status = '<span style="color:#9ca3af;font-weight:600;">○ Not configured</span> — add the client ID/secret, Save, then Connect Chase.';
        }

        return new HtmlString(
            $status
            .'<div style="font-size:0.75rem;color:#6b7280;margin-top:0.35rem;">Redirect URI to allow-list in your Plaid dashboard (API → Allowed redirect URIs):<br><code>'.e(route('plaid.link.connect')).'</code></div>'
        );
    }
}
