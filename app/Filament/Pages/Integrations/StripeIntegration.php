<?php

namespace App\Filament\Pages\Integrations;

use App\Services\Stripe\StripeClient;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/** Stripe secret key + webhook secret behind the texted card payment links. */
class StripeIntegration extends IntegrationSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $title = 'Stripe (card payments)';

    protected static function settingsMap(): array
    {
        return [
            'stripe_secret_key' => ['sensitive' => true],
            'stripe_webhook_secret' => ['sensitive' => true],
        ];
    }

    protected function sections(): array
    {
        return [
            Section::make('Stripe (card payment links)')
                ->description('Powers the job view\'s "Text card payment link" button — one click creates a Stripe Payment Link (quote + a card surcharge, one line item; no Invoicing fee) and texts the customer a hosted pay-by-card URL. Paste your Stripe Secret key (Dashboard → Developers → API keys). Live vs test is detected from the key prefix. Stored encrypted at rest.')
                ->schema([
                    Placeholder::make('stripe_status')
                        ->label('Connection')
                        ->content(fn () => $this->stripeStatus()),
                    TextInput::make('stripe_secret_key')
                        ->label('Secret key')
                        ->password()
                        ->revealable()
                        ->placeholder('sk_live_… or sk_test_…')
                        ->helperText('The card surcharge % lives in config (glass.stripe.surcharge_percent), default 3%.'),
                    Placeholder::make('stripe_webhook_url')
                        ->label('Webhook endpoint')
                        ->content(fn () => new HtmlString(
                            'In Stripe → Developers → Webhooks, add an endpoint pointing at:<br>'
                            .'<code>'.e(route('webhooks.stripe')).'</code><br>'
                            .'subscribe it to the <strong>checkout.session.completed</strong> event, then paste its '
                            .'Signing secret (whsec_…) below. Paid card links then auto-record + auto-confirm on the '
                            .'job — no bank matching needed for cards.'
                        )),
                    TextInput::make('stripe_webhook_secret')
                        ->label('Webhook signing secret')
                        ->password()
                        ->revealable()
                        ->placeholder('whsec_…')
                        ->helperText('From the webhook endpoint you created in Stripe. Stored encrypted at rest.'),
                ]),
        ];
    }

    private function stripeStatus(): HtmlString
    {
        $client = app(StripeClient::class);

        if (! $client->configured()) {
            $status = '<span style="color:#9ca3af;font-weight:600;">○ Not configured</span> — paste your Stripe secret key and Save.';
        } elseif ($client->mode() === 'live') {
            $status = '<span style="color:#16a34a;font-weight:600;">● Live</span> — real cards will be charged.';
        } else {
            $status = '<span style="color:#d97706;font-weight:600;">● Test</span> — test key; no real charges.';
        }

        return new HtmlString($status);
    }
}
