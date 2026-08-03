<?php

use App\Filament\Resources\Jobs\JobResource;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Services\JobLifecycle;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'a@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['manager'],
    ])->assignRole('super_admin');
});

it('renders the job detail page with address and multiple parts', function () {
    $entity = Entity::where('code', 'LA')->first();
    $customer = Customer::create(['phone_e164' => '+13235553658', 'first_name' => 'Ron', 'last_name' => 'Seaton']);

    $job = app(JobLifecycle::class)->createFromIntake([
        'entity_id' => $entity->id,
        'phone_e164' => $customer->phone_e164,
        'first_name' => 'Ron',
        'last_name' => 'Seaton',
        'service_address' => '14163 Saddletree Ct, Sylmar, CA 91342, USA',
        'vehicle_year' => '2020', 'vehicle_make' => 'Honda', 'vehicle_model' => 'Pilot',
        'vin' => '5FNYF6H56LB000535', 'glass_position' => 'windshield',
        'part_number' => 'FW04239 GTY FYG',
        'parts_json' => [
            ['label' => 'FW04239 GTY FYG', 'cost_cents' => 15500],
            ['label' => 'USM F4101 GOLD', 'cost_cents' => 790],
        ],
        'part_cost_cents' => 16290,
        'quote_amount_cents' => 68200,
    ], $this->admin);

    $this->actingAs($this->admin)
        ->get(JobResource::getUrl('view', ['record' => $job]))
        ->assertSuccessful()
        ->assertSee('14163 Saddletree Ct')   // service address
        ->assertSee('FW04239 GTY FYG')       // part 1
        ->assertSee('USM F4101 GOLD')        // part 2
        ->assertSee('$162.90');              // summed part cost (total)
});
