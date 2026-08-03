<?php

use App\Models\Employee;
use App\Models\Entity;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);
});

function priorityTech(string $name, string $entityCode, ?int $priority = null): Employee
{
    return Employee::create([
        'name' => $name,
        'entity_id' => Entity::where('code', $entityCode)->value('id'),
        'priority' => $priority,
        'job_functions' => ['technician'],
        'active' => true,
    ]);
}

/**
 * Ranked names for a location's tech pool — the way every real caller uses the
 * scope (JobActions::locationTechOptions, the Employees table). Never query
 * Employee::orderByPriority() unscoped in a test: migrations seed non-tech
 * staff into the DB (e.g. the the calibration partner calibration-partner login), so an
 * unscoped list picks up rows the test never created.
 *
 * @return array<int, string>
 */
function poolTechNames(string $entityCode): array
{
    $poolIds = Entity::where('code', $entityCode)->first()->techPoolEntityIds();

    return Employee::whereIn('entity_id', $poolIds)->orderByPriority()->pluck('name')->all();
}

it('stores a priority ranking on a technician', function () {
    $tech = priorityTech('Jose', 'LA', 1);

    expect($tech->fresh()->priority)->toBe(1)
        ->and($tech->priorityLabel())->toBe('#1');
});

it('leaves a technician unranked by default', function () {
    $tech = priorityTech('Unranked Ulises', 'LA');

    expect($tech->fresh()->priority)->toBeNull()
        ->and($tech->priorityLabel())->toBeNull();
});

it('orders ranked techs first and unranked techs last', function () {
    priorityTech('Zeb', 'LA');
    priorityTech('Carlos', 'LA', 2);
    priorityTech('Ana', 'LA');
    priorityTech('Jose', 'LA', 1);

    expect(poolTechNames('LA'))->toBe(['Jose', 'Carlos', 'Ana', 'Zeb']);
});

it('falls back to alphabetical when two techs share a rank', function () {
    priorityTech('Beto', 'LA', 2);
    priorityTech('Aaron', 'LA', 2);

    expect(poolTechNames('LA'))->toBe(['Aaron', 'Beto']);
});

it('ranks LA and SFV techs against each other as one pool', function () {
    priorityTech('SFV Sam', 'SFV', 1);
    priorityTech('LA Luis', 'LA', 2);

    expect(poolTechNames('LA'))->toBe(['SFV Sam', 'LA Luis']);
});

it('keeps Del Mar rankings independent of the LA/SFV pool', function () {
    priorityTech('LA Luis', 'LA', 1);
    priorityTech('DelMar Dani', 'DelMar', 1);

    // Del Mar's #1 is its own — the LA #1 doesn't collide with or outrank it.
    expect(poolTechNames('DelMar'))->toBe(['DelMar Dani']);
});

it('lists only ranked techs via the ranked scope', function () {
    priorityTech('Jose', 'LA', 1);
    priorityTech('Zeb', 'LA');

    expect(Employee::ranked()->pluck('name')->all())->toBe(['Jose']);
});
