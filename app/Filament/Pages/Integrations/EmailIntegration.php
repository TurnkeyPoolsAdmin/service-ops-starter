<?php

namespace App\Filament\Pages\Integrations;

use App\Services\Mail\ResendMailer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/** Outbound email (Resend): calibration partner alerts + password-reset links. */
class EmailIntegration extends IntegrationSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $title = 'Email / Resend';

    protected static function settingsMap(): array
    {
        return [
            'resend_api_key' => ['sensitive' => true],
            'mail_from_address' => [],
            'mail_from_name' => ['default' => 'Service Ops'],
        ];
    }

    protected function integrationActions(): array
    {
        return [
            Action::make('testResend')
                ->label('Send test email (Resend)')
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('gray')
                ->action(fn () => $this->sendResendTest()),
        ];
    }

    protected function sections(): array
    {
        return [
            Section::make('Email / Resend')
                ->description('Outbound email for calibration partner alerts AND the "Forgot password?" reset link on the login page — paste your API key and a verified from address below and both start using Resend automatically (falls back to log/MAIL_* until this is filled in). Stored encrypted at rest. Save, then use "Send test email" (top right) to confirm. Domain must be verified in the Resend dashboard.')
                ->schema([
                    Placeholder::make('resend_status')
                        ->label('Connection')
                        ->content(fn () => $this->resendStatus()),
                    TextInput::make('resend_api_key')
                        ->label('Resend API key')
                        ->password()
                        ->revealable()
                        ->placeholder('re_…')
                        ->helperText('From resend.com → API Keys. Stored encrypted at rest.'),
                    TextInput::make('mail_from_address')
                        ->label('From address')
                        ->email()
                        ->placeholder('noreply@example.com')
                        ->helperText('Must be on a domain verified in Resend.'),
                    TextInput::make('mail_from_name')
                        ->label('From name')
                        ->placeholder('Service Ops')
                        ->helperText('Display name recipients see (e.g. "Service Ops").'),
                ]),
        ];
    }

    private function resendStatus(): HtmlString
    {
        $mailer = app(ResendMailer::class);

        if (! $mailer->configured()) {
            $status = '<span style="color:#9ca3af;font-weight:600;">○ Not configured</span> — add the API key and from address, then Save.';
        } else {
            $status = '<span style="color:#16a34a;font-weight:600;">● Configured</span> — from '
                .e($mailer->fromName()).' &lt;'.e((string) $mailer->fromAddress()).'&gt;.';
        }

        return new HtmlString($status);
    }

    /** "Send test email" — posts a one-off Resend message to the current user. */
    private function sendResendTest(): void
    {
        $mailer = app(ResendMailer::class);

        if (! $mailer->configured()) {
            Notification::make()
                ->title('Resend not configured')
                ->body('Add the API key and from address, Save, then try again.')
                ->warning()
                ->send();

            return;
        }

        $to = auth()->user()?->email;
        if (blank($to)) {
            Notification::make()
                ->title('No email on your account')
                ->body('Your login user needs an email address to receive the test.')
                ->warning()
                ->send();

            return;
        }

        $by = auth()->user()?->name ?? 'someone';
        $result = $mailer->send(
            $to,
            'Service Ops — Resend test',
            "This is a test email from Service Ops (sent by {$by}). If you can read this, Resend is wired up correctly.",
        );

        if ($result['ok']) {
            Notification::make()
                ->title('Test email sent')
                ->body('Check '.$to.' (and spam). Resend id: '.($result['id'] ?? '—'))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Resend test failed')
                ->body($result['error'] ?? 'Unknown error')
                ->danger()
                ->send();
        }
    }
}
