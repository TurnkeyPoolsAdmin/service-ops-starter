<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatedByPermission;
use App\Models\Setting;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Admin > Timing. Reminder send times (local wall-clock).
 * Values are read at runtime by SettingsRepository.
 */
class ManageTiming extends Page
{
    use GatedByPermission;

    protected static function requiredPermission(): string
    {
        return 'manage_settings';
    }

    // Reached via the Admin > Settings hub, not its own sidebar item.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected string $view = 'filament.pages.settings-form';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $title = 'Reminder Timing';

    protected static ?int $navigationSort = 11;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'reminder_night_before_at' => Setting::get('reminder_night_before_at', '18:00'),
            'reminder_morning_of_at' => Setting::get('reminder_morning_of_at', '08:00'),
            'tech_reminders_enabled' => Setting::get('tech_reminders_enabled', '1') !== '0',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Appointment reminders')
                    ->description('Local (Pacific) wall-clock times. Guarded: never sent in the past or after work completes.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('reminder_night_before_at')->label('Night-before send time')->placeholder('18:00')->required(),
                        TextInput::make('reminder_morning_of_at')->label('Morning-of send time')->placeholder('08:00')->required(),
                    ]),
                Section::make('Technician reminders')
                    ->description('Texts each tech a digest of their jobs the night before and morning of, using the same send times above. Same-day-booked jobs are excluded; next-day-booked jobs get the morning-of digest only.')
                    ->schema([
                        Toggle::make('tech_reminders_enabled')->label('Send tech job-list reminders'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ([
            'reminder_night_before_at', 'reminder_morning_of_at',
        ] as $key) {
            Setting::put($key, (string) ($data[$key] ?? ''), sensitive: false);
        }

        Setting::put('tech_reminders_enabled', ($data['tech_reminders_enabled'] ?? true) ? '1' : '0', sensitive: false);

        Notification::make()->title('Timing settings saved')->success()->send();
    }
}
