<?php

use App\Filament\Pages\Finances;
use App\Filament\Pages\Settings;
use App\Models\Employee;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'a@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['manager'],
    ])->assignRole('super_admin');

    $this->dispatch = Employee::create([
        'name' => 'Dispatch', 'email' => 'd@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->dispatch->assignRole('dispatch');
});

it('shows the Settings hub with every settings tile to a super_admin', function () {
    $this->actingAs($this->admin);

    $this->get(Settings::getUrl())
        ->assertSuccessful()
        ->assertSee('Employees')
        ->assertSee('Message Templates')
        ->assertSee('Integrations')
        ->assertSee('Reminder Timing')
        ->assertSee('Holidays');
});

it('shows the Finance hub with every finance tile to a super_admin', function () {
    $this->actingAs($this->admin);

    $this->get(Finances::getUrl())
        ->assertSuccessful()
        ->assertSee('End-of-Day Summary')
        ->assertSee('Tech Balances')
        ->assertSee('Bank Transactions');
});

it('blocks dispatch from the Settings hub (no admin config permissions)', function () {
    $this->actingAs($this->dispatch);

    $this->get(Settings::getUrl())->assertForbidden();
});

it('gives dispatch only the finance tiles it is permitted (payout, not bank)', function () {
    // Dispatch has record_tech_payout (part of ops) but not manage_settings, so
    // the Finance hub opens and shows the two payout tiles but hides Bank Transactions.
    $this->actingAs($this->dispatch);

    $this->get(Finances::getUrl())
        ->assertSuccessful()
        ->assertSee('End-of-Day Summary')
        ->assertSee('Tech Balances')
        ->assertDontSee('Bank Transactions');
});

it('hides the folded-in resources and pages from the sidebar navigation', function () {
    // The individual admin items no longer register their own nav entry — only
    // the two hubs do. shouldRegisterNavigation() must be false for the rest.
    expect(\App\Filament\Resources\Employees\EmployeeResource::shouldRegisterNavigation())->toBeFalse();
    expect(\App\Filament\Resources\BankTransactions\BankTransactionResource::shouldRegisterNavigation())->toBeFalse();
    expect(\App\Filament\Pages\TechPayments::shouldRegisterNavigation())->toBeFalse();

    $this->actingAs($this->admin);
    expect(Settings::shouldRegisterNavigation())->toBeTrue();
    expect(Finances::shouldRegisterNavigation())->toBeTrue();
});
