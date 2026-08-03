<?php

use App\Filament\Resources\Jobs\Pages\EditJob;
use App\Models\Employee;
use App\Models\Entity;
use App\Services\JobLifecycle;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'a@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['manager'],
    ])->assignRole('super_admin');

    $this->actingAs($this->admin);
});

it('renders the job edit form without erroring on the status field', function () {
    $job = app(JobLifecycle::class)->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Al', 'quote_amount_cents' => 50000,
    ], $this->admin);

    // Regression: the disabled status field used to 500 (->label() on a string).
    Livewire::test(EditJob::class, ['record' => $job->getRouteKey()])
        ->assertSuccessful()
        ->assertFormSet(['status' => 'Pending schedule']);
});
