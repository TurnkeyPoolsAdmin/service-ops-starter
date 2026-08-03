<?php

use App\Filament\Pages\ManageIntegrations;
use App\Filament\Pages\ManageTiming;
use App\Filament\Pages\RoasDashboard;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Entities\EntityResource;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\Employee;
use App\Models\Entity;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'admin@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['manager'],
    ]);
    $this->admin->assignRole('admin');

    $this->superAdmin = Employee::create([
        'name' => 'Owner', 'email' => 'owner@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['manager'],
    ])->assignRole('super_admin');
});

it('grants the admin role entities, employees, warehouses and delete-jobs but withholds the rest', function () {
    expect($this->admin->can('manage_entities'))->toBeTrue()
        ->and($this->admin->can('manage_employees'))->toBeTrue()
        ->and($this->admin->can('manage_warehouses'))->toBeTrue()
        ->and($this->admin->can('delete_jobs'))->toBeTrue()
        ->and($this->admin->can('access_admin'))->toBeTrue()
        // Withheld:
        ->and($this->admin->can('manage_templates'))->toBeFalse()
        ->and($this->admin->can('manage_settings'))->toBeFalse()
        ->and($this->admin->can('view_roas'))->toBeFalse();
});

it('gates the admin section pages by the admin role', function () {
    $this->actingAs($this->admin);

    expect(EntityResource::canAccess())->toBeTrue()
        ->and(EmployeeResource::canAccess())->toBeTrue()
        ->and(WarehouseResource::canAccess())->toBeTrue()
        ->and(MessageTemplateResource::canAccess())->toBeFalse()
        ->and(ManageIntegrations::canAccess())->toBeFalse()
        ->and(ManageTiming::canAccess())->toBeFalse()
        ->and(RoasDashboard::canAccess())->toBeFalse();
});

it('lets the super_admin reach everything', function () {
    $this->actingAs($this->superAdmin);

    expect(MessageTemplateResource::canAccess())->toBeTrue()
        ->and(WarehouseResource::canAccess())->toBeTrue()
        ->and(ManageIntegrations::canAccess())->toBeTrue()
        ->and(RoasDashboard::canAccess())->toBeTrue();
});

it('hides the delete action for the super_admin account but shows it for others', function () {
    $this->actingAs($this->superAdmin);

    Livewire::test(EditEmployee::class, ['record' => $this->superAdmin->getKey()])
        ->assertActionHidden('delete');

    Livewire::test(EditEmployee::class, ['record' => $this->admin->getKey()])
        ->assertActionVisible('delete');
});

it('disables the roles field when an admin edits an elevated account, and preserves the role on save', function () {
    $this->actingAs($this->admin);

    Livewire::test(EditEmployee::class, ['record' => $this->superAdmin->getKey()])
        ->assertFormFieldIsDisabled('roles')
        ->set('data.name', 'Owner Renamed')
        ->call('save')
        ->assertHasNoErrors();

    // The super_admin role must survive an admin's edit.
    expect($this->superAdmin->refresh()->hasRole('super_admin'))->toBeTrue();
});

it('lets a super_admin edit the roles field', function () {
    $this->actingAs($this->superAdmin);

    Livewire::test(EditEmployee::class, ['record' => $this->admin->getKey()])
        ->assertFormFieldIsEnabled('roles');
});

it('blocks an admin from granting the admin role', function () {
    $this->actingAs($this->admin);
    $adminRoleId = \Spatie\Permission\Models\Role::where('name', 'admin')->value('id');

    Livewire::test(\App\Filament\Resources\Employees\Pages\CreateEmployee::class)
        ->set('data.name', 'New Guy')
        ->set('data.can_login', true)
        ->set('data.password', 'secret123')
        ->set('data.roles', [$adminRoleId])
        ->call('create');

    // Whether validation rejects it or it's silently dropped, no admin role is granted.
    expect(Employee::where('name', 'New Guy')->first()?->hasRole('admin'))->not->toBeTrue();
});

it('stores the entity a technician is attached to', function () {
    $la = Entity::where('code', 'LA')->first();

    $tech = Employee::create([
        'name' => 'Tina Tech', 'active' => true,
        'job_functions' => ['technician'], 'entity_id' => $la->id,
    ]);

    expect($tech->refresh()->entity->code)->toBe('LA');
});
