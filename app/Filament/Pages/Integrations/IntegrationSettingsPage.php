<?php

namespace App\Filament\Pages\Integrations;

use App\Filament\Concerns\GatedByPermission;
use App\Filament\Pages\ManageIntegrations;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Base for the one-integration-per-page settings screens reached from the
 * Admin > Settings > Integrations tile hub. Each subclass declares the settings
 * keys it owns (settingsMap()) and the form sections that edit them; this class
 * does the identical mount/fill + save/persist plumbing they all shared when
 * they were a single wall of sections on ManageIntegrations.
 *
 * settingsMap() is `setting key => ['sensitive' => bool, 'type' => 'string'|'bool',
 * 'default' => mixed]`. 'sensitive' encrypts at rest exactly as before; 'bool'
 * keys round-trip through the '1'/'0' strings the Setting table stores.
 */
abstract class IntegrationSettingsPage extends Page
{
    use GatedByPermission;

    protected string $view = 'filament.pages.settings-form';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    public ?array $data = [];

    /** Every integration screen is admin-only, same as the old single page. */
    protected static function requiredPermission(): string
    {
        return 'manage_settings';
    }

    // Reached via the Settings > Integrations hub, not its own sidebar item.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /** @return array<string, array{sensitive?: bool, type?: string, default?: mixed}> */
    abstract protected static function settingsMap(): array;

    /** The form sections for this integration. */
    abstract protected function sections(): array;

    /** Actions specific to this integration (test sends, OAuth connects, …). */
    protected function integrationActions(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return array_merge($this->integrationActions(), [
            Action::make('backToIntegrations')
                ->label('All integrations')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->url(fn () => ManageIntegrations::getUrl()),
        ]);
    }

    public function mount(): void
    {
        $state = [];

        foreach (static::settingsMap() as $key => $spec) {
            $state[$key] = static::readSetting($key, $spec);
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->sections())->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $this->beforeSaving($data);

        foreach (static::settingsMap() as $key => $spec) {
            Setting::put(
                $key,
                static::castForStorage($data[$key] ?? null, $spec),
                sensitive: (bool) ($spec['sensitive'] ?? false),
            );
        }

        Notification::make()->title(static::savedMessage())->success()->send();
    }

    /** Hook for side effects that must run BEFORE the new values are persisted. */
    protected function beforeSaving(array $data): void {}

    protected static function savedMessage(): string
    {
        return 'Integration settings saved';
    }

    /** Reads one setting back into form state, honouring its type + default. */
    protected static function readSetting(string $key, array $spec): mixed
    {
        $raw = Setting::get($key);

        if (($spec['type'] ?? 'string') === 'bool') {
            return $raw === null ? (bool) ($spec['default'] ?? false) : $raw === '1';
        }

        return $raw ?? ($spec['default'] ?? null);
    }

    protected static function castForStorage(mixed $value, array $spec): ?string
    {
        if (($spec['type'] ?? 'string') === 'bool') {
            return $value ? '1' : '0';
        }

        return $value === null ? null : (string) $value;
    }
}
