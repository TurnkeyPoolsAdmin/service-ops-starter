<?php

use App\Filament\Pages\Integrations\IntegrationSettingsPage;
use App\Filament\Pages\ManageIntegrations;
use App\Models\Employee;
use App\Models\Setting;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->superAdmin = Employee::create([
        'name' => 'Owner', 'email' => 'owner@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['manager'],
    ]);
    $this->superAdmin->assignRole('super_admin');

    // The Sending status page requires a number before it will save.
    Setting::put('sending_phone_e164', '+18185550000', sensitive: false);
});

/** Every tile the Integrations hub offers, as page classes. */
function integrationPageClasses(): array
{
    $method = new ReflectionMethod(ManageIntegrations::class, 'tileClasses');
    $method->setAccessible(true);

    return array_column($method->invoke(null), 'class');
}

it('splits integrations into one page per service', function () {
    $classes = integrationPageClasses();

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        expect(is_subclass_of($class, IntegrationSettingsPage::class))->toBeTrue("{$class} is not an integration page")
            // Each lives under Integrations, not the sidebar.
            ->and($class::shouldRegisterNavigation())->toBeFalse();
    }
});

it('loads and saves every integration page as super_admin', function () {
    $this->actingAs($this->superAdmin);

    foreach (integrationPageClasses() as $class) {
        $this->get($class::getUrl())->assertSuccessful();

        // Round-trips its own settings without touching anyone else's.
        Livewire::test($class)->call('save')->assertHasNoErrors();
    }
});

it('keeps every integration page admin-only', function () {
    $dispatch = Employee::create([
        'name' => 'Dispatch', 'email' => 'd@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $dispatch->assignRole('dispatch');
    $this->actingAs($dispatch);

    expect(ManageIntegrations::canAccess())->toBeFalse();

    foreach (integrationPageClasses() as $class) {
        expect($class::canAccess())->toBeFalse("{$class} is reachable by dispatch");
    }
});

it('preserves saved credentials across the split', function () {
    $this->actingAs($this->superAdmin);

    Setting::put('openphone_api_key', 'op_secret', sensitive: true);
    Setting::put('telegram_chat_id', '-1001234567890', sensitive: false);

    // Saving the Quo page must not blank an unrelated integration's settings.
    Livewire::test(\App\Filament\Pages\Integrations\QuoIntegration::class)->call('save');

    expect(Setting::get('openphone_api_key'))->toBe('op_secret')
        ->and(Setting::get('telegram_chat_id'))->toBe('-1001234567890');
});
