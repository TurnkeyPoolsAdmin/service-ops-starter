<?php

namespace App\Filament\Pages\Integrations;

use App\Models\Setting;
use App\Services\Messaging\Providers\MessageProviderFactory;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;

/**
 * The master texting switches: the pause, which provider sends, and the single
 * global sending number. Provider credentials live on their own pages.
 */
class SendingStatus extends IntegrationSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $title = 'Sending status & provider';

    /** Owner's phone — alerted whenever outgoing texts get paused. */
    private const ALERT_PHONE_E164 = '+15555550100';

    protected static function settingsMap(): array
    {
        return [
            'outgoing_sms_paused' => ['type' => 'bool', 'default' => false],
            'outgoing_provider' => ['default' => 'openphone'],
            'sending_phone_e164' => [],
        ];
    }

    protected function sections(): array
    {
        return [
            Section::make('Sending status')
                ->description('Master controls for all automated customer texts.')
                ->schema([
                    Toggle::make('outgoing_sms_paused')
                        ->label('Pause outgoing texts')
                        ->helperText('When on, no automated texts are sent (they are logged instead). Use during build mode.'),
                    Select::make('outgoing_provider')
                        ->label('Outgoing texting provider')
                        ->options([
                            'openphone' => 'Quo / OpenPhone',
                            'podium' => 'Podium',
                            'ringcentral' => 'RingCentral',
                        ])
                        ->native(false)
                        ->selectablePlaceholder(false)
                        ->helperText('Which service sends outgoing texts. Switch any time — only takes effect in production while not paused.'),
                ]),
            Section::make('Outbound SMS (Quo / OpenPhone)')
                ->description('Sending number used when the provider is Quo/OpenPhone. (Podium sends from its own location number.)')
                ->schema([
                    TextInput::make('sending_phone_e164')
                        ->label('Sending phone (E.164)')
                        ->placeholder('+18185550000')
                        ->required(),
                ]),
        ];
    }

    protected static function savedMessage(): string
    {
        return 'Sending settings saved';
    }

    /**
     * Turning the pause ON: alert the owner BEFORE persisting the flag, so the
     * alert itself still goes out through the live provider (the pause setting
     * isn't written yet, so MessageProviderFactory hasn't kicked in).
     */
    protected function beforeSaving(array $data): void
    {
        $wasPaused = Setting::get('outgoing_sms_paused') === '1';
        $willBePaused = (bool) ($data['outgoing_sms_paused'] ?? false);

        if ($willBePaused && ! $wasPaused) {
            $this->sendPauseAlert($data);
        }
    }

    /** Sends a same-request alert text to the owner when the pause is switched on. */
    private function sendPauseAlert(array $data): void
    {
        $fromPhone = $data['sending_phone_e164'] ?? Setting::get('sending_phone_e164');
        if (blank($fromPhone)) {
            Log::warning('SendingStatus: pause alert skipped, no sending_phone_e164 configured.');

            return;
        }

        $by = auth()->user()?->name ?? 'someone';
        $body = "⚠️ Service Ops: Outgoing texts were just PAUSED by {$by}. No automated customer texts will send until this is turned back off.";

        try {
            $result = app(MessageProviderFactory::class)
                ->make($data['outgoing_provider'] ?? null)
                ->send(self::ALERT_PHONE_E164, $fromPhone, $body);

            if (! $result->success) {
                Log::warning('SendingStatus: pause alert failed to send.', [
                    'error_code' => $result->errorCode,
                    'error_message' => $result->errorMessage,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('SendingStatus: pause alert threw.', ['error' => $e->getMessage()]);
        }
    }
}
