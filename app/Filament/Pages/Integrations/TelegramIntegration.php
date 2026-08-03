<?php

namespace App\Filament\Pages\Integrations;

use App\Models\Setting;
use App\Services\Telegram\TelegramNotifier;
use App\Support\AlertWindow;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/** The one shared alerts bot + group chat every Telegram alert posts through. */
class TelegramIntegration extends IntegrationSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $title = 'Telegram (internal alerts)';

    protected static function settingsMap(): array
    {
        return [
            'telegram_alerts_enabled' => ['type' => 'bool', 'default' => true],
            'telegram_bot_token' => ['sensitive' => true],
            'telegram_chat_id' => [],
        ];
    }

    protected function integrationActions(): array
    {
        return [
            Action::make('testTelegram')
                ->label('Send test to Telegram')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->action(fn () => $this->sendTelegramTest()),
        ];
    }

    protected function sections(): array
    {
        return [
            Section::make('Telegram (internal alerts)')
                ->description('Posts internal ops alerts to a team Telegram group chat — the 7am / 12pm / 5pm ops digests (jobs needing a schedule, parts to order, parts to prepay, completed but unpaid) plus the overdue, warranty, review-call and vendor alerts. Create a bot with @BotFather, add it to the group, then get the chat id (see helper text). Save, then use "Send test to Telegram" (top right) to confirm. Token stored encrypted at rest.')
                ->schema([
                    Placeholder::make('telegram_status')
                        ->label('Connection')
                        ->content(fn () => $this->telegramStatus()),
                    Toggle::make('telegram_alerts_enabled')
                        ->label('Send Telegram alerts')
                        ->helperText('Master switch for all automated Telegram posts. When off, the daily digest is skipped. Alerts never post on Sunday — the shop is closed.'),
                    TextInput::make('telegram_bot_token')
                        ->label('Bot token')
                        ->password()
                        ->revealable()
                        ->placeholder('123456789:ABCdef…')
                        ->helperText('From @BotFather when you create the bot.'),
                    TextInput::make('telegram_chat_id')
                        ->label('Group chat id')
                        ->placeholder('-1001234567890')
                        ->helperText('The destination group\'s numeric id (group ids are negative). Add the bot to the group, send a message, then open https://api.telegram.org/bot<token>/getUpdates to read the chat id.'),
                ]),
        ];
    }

    private function telegramStatus(): HtmlString
    {
        $configured = app(TelegramNotifier::class)->configured();
        $enabled = Setting::get('telegram_alerts_enabled', '1') !== '0';

        if (! $configured) {
            $status = '<span style="color:#9ca3af;font-weight:600;">○ Not configured</span> — add the bot token and group chat id, then Save.';
        } elseif (! $enabled) {
            $status = '<span style="color:#d97706;font-weight:600;">● Configured, alerts off</span> — turn "Send Telegram alerts" on to resume the daily digest.';
        } elseif (AlertWindow::silentToday()) {
            $status = '<span style="color:#d97706;font-weight:600;">● Connected, quiet today</span> — we are closed today, so no alerts post. They resume tomorrow morning.';
        } else {
            $status = '<span style="color:#16a34a;font-weight:600;">● Connected</span> — the daily digest posts at 7:00am PT.';
        }

        return new HtmlString($status);
    }

    /** "Send test to Telegram" — posts a one-off message to confirm the bot works. */
    private function sendTelegramTest(): void
    {
        $telegram = app(TelegramNotifier::class);

        if (! $telegram->configured()) {
            Notification::make()
                ->title('Telegram not configured')
                ->body('Add the bot token and group chat id, Save, then try again.')
                ->warning()
                ->send();

            return;
        }

        $by = auth()->user()?->name ?? 'someone';
        // force: a human is standing here waiting to see the bot work, so the
        // test posts even on a silent day (a held test reads as a broken bot).
        $result = $telegram->send(
            "✅ Service Ops test message (sent by {$by}). If you can read this, the alerts bot is wired up correctly.",
            force: true,
        );

        if ($result->success) {
            Notification::make()->title('Test message sent to Telegram')->success()->send();
        } else {
            Notification::make()
                ->title('Telegram test failed')
                ->body(trim(($result->errorCode ?? '').' '.($result->errorMessage ?? '')))
                ->danger()
                ->send();
        }
    }
}
