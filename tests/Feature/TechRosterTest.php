<?php

use App\Filament\Pages\TechRoster;
use App\Models\Employee;
use App\Models\Entity;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'a@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->admin->assignRole('admin');

    $this->dispatch = Employee::create([
        'name' => 'Dispatch', 'email' => 'd@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->dispatch->assignRole('dispatch');
});

function rosterTech(string $name, string $entityCode, ?int $priority = null, ?int $fixedCostCents = null): Employee
{
    return Employee::create([
        'name' => $name,
        'entity_id' => Entity::where('code', $entityCode)->value('id'),
        'priority' => $priority,
        'fixed_cost_cents' => $fixedCostCents,
        'job_functions' => ['technician'],
        'active' => true,
    ]);
}

it('admin can access Tech Roster; dispatch cannot', function () {
    expect(TechRoster::canAccess())->toBeFalse();

    $this->actingAs($this->admin);
    expect(TechRoster::canAccess())->toBeTrue();

    $this->actingAs($this->dispatch);
    expect(TechRoster::canAccess())->toBeFalse();
});

it('shows LA/SFV pooled techs together with their rank and rate, defaulting to that pool', function () {
    $sfv = rosterTech('SFV Sam', 'SFV', 1, 15000);
    $la = rosterTech('LA Luis', 'LA', 2, 12000);
    $delMar = rosterTech('DelMar Dani', 'DelMar', 1, 20000);

    Livewire::actingAs($this->admin)->test(TechRoster::class)
        ->assertSuccessful()
        ->assertSee('SFV / AGLA')
        ->assertSee($sfv->name)
        ->assertSee($la->name)
        ->assertSee('#1')
        ->assertSee('#2')
        ->assertSee('$150.00')
        ->assertSee('$120.00')
        ->assertDontSee($delMar->name);
});

it('shows Del Mar techs independently on the Del Mar tab', function () {
    $delMar = rosterTech('DelMar Dani', 'DelMar', 1, 20000);
    $la = rosterTech('LA Luis', 'LA', 1, 12000);

    $delMarEntityId = (string) Entity::where('code', 'DelMar')->value('id');

    Livewire::actingAs($this->admin)
        ->withQueryParams(['pool' => $delMarEntityId])
        ->test(TechRoster::class)
        ->assertSuccessful()
        ->assertSee($delMar->name)
        ->assertDontSee($la->name);
});

it('rewrites the pool ranking when rows are dragged, numbering the unranked too', function () {
    $sam = rosterTech('SFV Sam', 'SFV', 1);
    $luis = rosterTech('LA Luis', 'LA', 2);
    $vic = rosterTech('LA Vic', 'LA'); // unranked

    Livewire::actingAs($this->admin)->test(TechRoster::class)
        ->call('reorderTable', [$vic->id, $sam->id, $luis->id]);

    expect($vic->refresh()->priority)->toBe(1)
        ->and($sam->refresh()->priority)->toBe(2)
        ->and($luis->refresh()->priority)->toBe(3);
});

it('excludes non-technician staff from the roster', function () {
    $office = Employee::create([
        'name' => 'Office Olivia',
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'job_functions' => ['office'],
        'active' => true,
    ]);
    $tech = rosterTech('LA Luis', 'LA', 1, 12000);

    Livewire::actingAs($this->admin)->test(TechRoster::class)
        ->assertSuccessful()
        ->assertSee($tech->name)
        ->assertDontSee($office->name);
});
