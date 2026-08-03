<?php

use App\Filament\Resources\Jobs\JobResource;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Services\JobLifecycle;
use App\Support\JobSearch;
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

    $lifecycle = app(JobLifecycle::class);
    $la = Entity::where('code', 'LA')->value('id');

    $this->job = $lifecycle->createFromIntake([
        'entity_id' => $la,
        'phone_e164' => '+13105551234',
        'first_name' => 'John',
        'last_name' => 'Smith',
        'city' => 'Santa Monica',
        'service_address' => '1200 Ocean Ave',
        'vehicle_year' => '2019', 'vehicle_make' => 'Toyota', 'vehicle_model' => 'Camry',
        'part_number' => 'FW04321',
    ], $this->actor);

    $this->other = $lifecycle->createFromIntake([
        'entity_id' => $la,
        'phone_e164' => '+18185559999',
        'first_name' => 'Maria',
        'last_name' => 'Gonzalez',
        'city' => 'Van Nuys',
        'vehicle_make' => 'Honda', 'vehicle_model' => 'Civic',
    ], $this->actor);
});

/** @return array<int, int> */
function searchIds(string $term): array
{
    return JobSearch::apply(Job::query(), $term)->pluck('id')->all();
}

it('finds a job by first name, by last name, and in either order', function () {
    expect(searchIds('John'))->toBe([$this->job->id])
        ->and(searchIds('Smith'))->toBe([$this->job->id])
        ->and(searchIds('John Smith'))->toBe([$this->job->id])
        // The snapshot reads "John Smith" - a plain LIKE fails on both of these.
        ->and(searchIds('Smith John'))->toBe([$this->job->id])
        ->and(searchIds('Smith, John'))->toBe([$this->job->id]);
});

it('finds a job by phone in whatever format the operator types it', function () {
    // Stored E.164: +13105551234.
    expect(searchIds('3105551234'))->toBe([$this->job->id])
        ->and(searchIds('310-555-1234'))->toBe([$this->job->id])
        ->and(searchIds('(310) 555-1234'))->toBe([$this->job->id])
        ->and(searchIds('+1 310 555 1234'))->toBe([$this->job->id])
        ->and(searchIds('310.555.1234'))->toBe([$this->job->id])
        // A fragment is enough - operators type the last four.
        ->and(searchIds('5551234'))->toBe([$this->job->id]);
});

it('finds a job by number, address, city, vehicle and part number', function () {
    expect(searchIds($this->job->job_number))->toBe([$this->job->id])
        ->and(searchIds('Ocean Ave'))->toBe([$this->job->id])
        ->and(searchIds('Santa Monica'))->toBe([$this->job->id])
        ->and(searchIds('Toyota Camry'))->toBe([$this->job->id])
        ->and(searchIds('FW04321'))->toBe([$this->job->id]);
});

it('requires every word to match something, so it narrows rather than widens', function () {
    // "Camry" alone would also be a hit; paired with the wrong name it is not.
    expect(searchIds('Maria Camry'))->toBe([])
        ->and(searchIds('Smith Camry'))->toBe([$this->job->id])
        ->and(searchIds('Gonzalez Civic'))->toBe([$this->other->id]);
});

it('searches the customer record too, so a corrected name is findable', function () {
    // The snapshot is written once at intake and is now stale on purpose.
    $this->job->customer->update(['last_name' => 'Smithers']);

    expect($this->job->fresh()->customer_name_snapshot)->toBe('John Smith')
        ->and(searchIds('Smithers'))->toBe([$this->job->id]);
});

it('ignores digit runs too short to be a phone fragment', function () {
    // Maria's number is +18185559999. A two-digit word must not reach a phone
    // column, or every short number typed drags in half the board.
    expect(searchIds('99'))->toBe([]);
});

it('exposes name and phone to the topbar global search', function () {
    $attributes = JobResource::getGloballySearchableAttributes();

    expect($attributes)->toContain('customer_name_snapshot')
        ->and($attributes)->toContain('customer_phone_snapshot')
        ->and($attributes)->toContain('customer.last_name');

    $results = JobResource::getGlobalSearchResults('Smith');
    expect($results)->toHaveCount(1)
        ->and($results->first()->title)->toContain('John Smith')
        ->and($results->first()->details['Phone'])->toBe('(310) 555-1234');

    expect(JobResource::getGlobalSearchResults('(310) 555-1234'))->toHaveCount(1);
});
