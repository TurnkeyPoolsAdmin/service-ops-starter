<?php

use App\Filament\Pages\Intake;
use App\Filament\Pages\ManageIntegrations;
use App\Filament\Pages\ManageTiming;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Entities\EntityResource;
use App\Filament\Resources\Jobs\JobResource;
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

it('lets super_admin reach the admin section', function () {
    $this->actingAs($this->admin);

    $this->get(EntityResource::getUrl('index'))->assertSuccessful();
    $this->get(EmployeeResource::getUrl('index'))->assertSuccessful();
    $this->get(ManageIntegrations::getUrl())->assertSuccessful();
    $this->get(ManageTiming::getUrl())->assertSuccessful();
});

it('blocks dispatch from the admin section', function () {
    $this->actingAs($this->dispatch);

    $this->get(EntityResource::getUrl('index'))->assertForbidden();
    $this->get(EmployeeResource::getUrl('index'))->assertForbidden();
    $this->get(ManageIntegrations::getUrl())->assertForbidden();
    $this->get(ManageTiming::getUrl())->assertForbidden();
});

it('lets super_admin reach operations (jobs board + intake)', function () {
    $this->actingAs($this->admin);
    $this->get(JobResource::getUrl('index'))->assertSuccessful();
    $this->get(Intake::getUrl())->assertSuccessful();
});

it('lets dispatch reach operations (jobs board + intake)', function () {
    $this->actingAs($this->dispatch);
    $this->get(JobResource::getUrl('index'))->assertSuccessful();
    $this->get(Intake::getUrl())->assertSuccessful();
});

it('redirects guests to login', function () {
    $this->get(JobResource::getUrl('index'))->assertRedirect();
});
