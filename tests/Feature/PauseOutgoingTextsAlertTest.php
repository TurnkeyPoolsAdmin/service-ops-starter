<?php

use App\Filament\Pages\Integrations\SendingStatus;
use App\Filament\Pages\ManageIntegrations;
use App\Models\Employee;
use App\Models\Setting;
use App\Services\Messaging\Providers\MessageProvider;
use App\Services\Messaging\Providers\MessageProviderFactory;
use App\Services\Messaging\Providers\ProviderResult;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->superAdmin = Employee::create([
        'name' => 'Owner', 'email' => 'owner@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['manager'],
    ]);
    $this->superAdmin->assignRole('super_admin');

    Setting::put('sending_phone_e164', '+18185550000', sensitive: false);
    Setting::put('outgoing_provider', 'openphone', sensitive: false);
});

/** Route the provider factory to a test double and capture what it's asked to send. */
function fakePauseAlertProvider(array &$sent): void
{
    $provider = Mockery::mock(MessageProvider::class);
    $provider->shouldReceive('send')
        ->andReturnUsing(function (string $to, string $from, string $body) use (&$sent) {
            $sent[] = compact('to', 'from', 'body');

            return ProviderResult::ok('fake_id');
        });

    $factory = Mockery::mock(MessageProviderFactory::class);
    $factory->shouldReceive('make')->andReturn($provider);
    app()->instance(MessageProviderFactory::class, $factory);
}

it('only lets super_admin access the pause toggle', function () {
    $dispatch = Employee::create([
        'name' => 'Dispatch', 'email' => 'd@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $dispatch->assignRole('dispatch');

    expect(ManageIntegrations::canAccess())->toBeFalse();

    $this->actingAs($this->superAdmin);
    expect(ManageIntegrations::canAccess())->toBeTrue();
});

it('texts the owner alert number when outgoing texts are paused', function () {
    $this->actingAs($this->superAdmin);
    Setting::put('outgoing_sms_paused', '0', sensitive: false);

    $sent = [];
    fakePauseAlertProvider($sent);

    expect(Setting::get('outgoing_sms_paused'))->not->toBe('1');

    Livewire::test(SendingStatus::class)
        ->set('data.outgoing_sms_paused', true)
        ->call('save');

    expect(Setting::get('outgoing_sms_paused'))->toBe('1')
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]['to'])->toBe('+15555550100')
        ->and($sent[0]['body'])->toContain('PAUSED');
});

it('does not send an alert when saving while still paused or when unpausing', function () {
    $this->actingAs($this->superAdmin);
    Setting::put('outgoing_sms_paused', '1', sensitive: false);

    $sent = [];
    fakePauseAlertProvider($sent);

    Livewire::test(SendingStatus::class)
        ->set('data.outgoing_sms_paused', false)
        ->call('save');

    expect(Setting::get('outgoing_sms_paused'))->toBe('0')
        ->and($sent)->toHaveCount(0);
});
