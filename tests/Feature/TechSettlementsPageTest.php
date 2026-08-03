<?php

use App\Filament\Pages\TechSettlements;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\TechSettlement;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->dispatch = Employee::create([
        'name' => 'Dispatch', 'email' => 'd@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ])->assignRole('dispatch');

    $this->tech = Employee::create([
        'name' => 'Tina Tech', 'email' => 't@test.local', 'can_login' => false, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['technician'],
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'cash_balance_cents' => 8000,
    ]);
});

it('lists techs who owe us cash', function () {
    // A tech with no balance should not appear.
    Employee::create([
        'name' => 'Even Eddie', 'email' => 'e@test.local', 'can_login' => false, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['technician'], 'cash_balance_cents' => 0,
    ]);

    Livewire::actingAs($this->dispatch)
        ->test(TechSettlements::class)
        ->assertSuccessful()
        ->assertSee('Tina Tech')
        ->assertSee('$80.00')
        ->assertDontSee('Even Eddie');
});

it('clears the balance when the tech hands the cash back', function () {
    Livewire::actingAs($this->dispatch)
        ->test(TechSettlements::class)
        ->callTableAction('clear', $this->tech, data: ['method' => 'zelle', 'reference' => 'z-7'])
        ->assertHasNoTableActionErrors();

    expect($this->tech->refresh()->cashOwedCents())->toBe(0);

    $settlement = TechSettlement::where('technician_id', $this->tech->id)->first();
    expect($settlement->type)->toBe('balance_clear')
        ->and($settlement->amount_cents)->toBe(8000)
        ->and($settlement->direction)->toBe('collected_from_tech')
        ->and($settlement->balance_after_cents)->toBe(0);
});

it('blocks an employee without the payout permission', function () {
    $nobody = Employee::create([
        'name' => 'Nobody', 'email' => 'n@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);

    $this->actingAs($nobody)
        ->get(TechSettlements::getUrl())
        ->assertForbidden();
});
