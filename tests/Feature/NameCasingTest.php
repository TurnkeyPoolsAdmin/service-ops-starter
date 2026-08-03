<?php

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Services\JobLifecycle;
use App\Services\Messaging\TemplateRenderer;
use App\Support\Name;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');
    $this->actingAs($this->actor);

    $this->lifecycle = app(JobLifecycle::class);
});

it('normalizes ALL-CAPS and lowercase names but preserves intentional mixed case', function () {
    expect(Name::normalizeCase('JOHN'))->toBe('John')
        ->and(Name::normalizeCase('john'))->toBe('John')
        ->and(Name::normalizeCase('JOHN SMITH'))->toBe('John Smith')
        ->and(Name::normalizeCase("O'BRIEN"))->toBe("O'Brien")
        ->and(Name::normalizeCase('SMITH-JONES'))->toBe('Smith-Jones')
        ->and(Name::normalizeCase('McDonald'))->toBe('McDonald')
        ->and(Name::normalizeCase('DeShawn'))->toBe('DeShawn')
        ->and(Name::normalizeCase('J'))->toBe('J')
        ->and(Name::normalizeCase('JOHN smith'))->toBe('John Smith')
        ->and(Name::normalizeCase(null))->toBeNull()
        ->and(Name::normalizeCase(''))->toBe('');
});

it('normalizes an ALL-CAPS lead paste on the customer, the job snapshot, and the {first_name} merge var', function () {
    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'MARIA',
        'last_name' => 'GONZALEZ-LOPEZ',
    ], $this->actor);

    expect($job->customer->first_name)->toBe('Maria')
        ->and($job->customer->last_name)->toBe('Gonzalez-Lopez')
        ->and($job->customer->full_name)->toBe('Maria Gonzalez-Lopez')
        ->and($job->customer_name_snapshot)->toBe('Maria Gonzalez-Lopez');

    $vars = app(TemplateRenderer::class)->varsForJob($job);
    expect($vars['first_name'])->toBe('Maria');
});

it('normalizes direct customer edits too', function () {
    $customer = Customer::create(['phone_e164' => '+18185550001', 'first_name' => 'BOB']);
    expect($customer->first_name)->toBe('Bob');

    $customer->update(['first_name' => 'ROBERT', 'last_name' => 'de la CRUZ']);
    expect($customer->refresh()->first_name)->toBe('Robert')
        // Mixed-case parts stay; all-caps/lowercase parts normalize.
        ->and($customer->last_name)->toBe('De La Cruz');
});
