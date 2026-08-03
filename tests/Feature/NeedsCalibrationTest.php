<?php

use App\Models\Employee;
use App\Models\Entity;
use App\Services\JobLifecycle;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->lifecycle = app(JobLifecycle::class);
});

function calibrationIntake(array $overrides = []): array
{
    return array_merge([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Cal',
        'quote_amount_cents' => 20000,
        'intake_raw_text' => 'raw',
    ], $overrides);
}

it('flags a job as needing calibration at intake', function () {
    $job = $this->lifecycle->createFromIntake(calibrationIntake(['needs_calibration' => true]), $this->actor);

    expect($job->needs_calibration)->toBeTrue()
        ->and($job->needsCalibration())->toBeTrue();
});

it('defaults to not needing calibration when the flag is omitted', function () {
    $job = $this->lifecycle->createFromIntake(calibrationIntake(), $this->actor);

    expect($job->needs_calibration)->toBeFalse()
        ->and($job->needsCalibration())->toBeFalse();
});

it('always needs calibration for an insurance WINDSHIELD, even without the flag', function () {
    $job = $this->lifecycle->createFromIntake(
        calibrationIntake(['is_insurance' => true, 'needs_calibration' => false, 'glass_position' => 'Windshield']),
        $this->actor,
    );

    // createFromIntake forces the stored flag on for insurance (so dashboards
    // and partner alerts don't depend only on the derived method).
    expect($job->needs_calibration)->toBeTrue()
        ->and($job->is_insurance)->toBeTrue()
        ->and($job->needsCalibration())->toBeTrue();
});

it('does NOT force calibration on an insurance job that is not a windshield', function (?string $position) {
    $job = $this->lifecycle->createFromIntake(
        calibrationIntake(['is_insurance' => true, 'glass_position' => $position]),
        $this->actor,
    );

    expect($job->needs_calibration)->toBeFalse()
        ->and($job->is_insurance)->toBeTrue()
        ->and($job->needsCalibration())->toBeFalse();
})->with([['Back Glass'], ['Rear Right Door'], ['Sunroof'], [null]]);

it('still honors the manual flag on a non-windshield insurance job', function () {
    $job = $this->lifecycle->createFromIntake(
        calibrationIntake(['is_insurance' => true, 'needs_calibration' => true, 'glass_position' => 'Back Glass']),
        $this->actor,
    );

    expect($job->needsCalibration())->toBeTrue();
});

it('derives needsCalibration from insurance on a windshield even if the stored flag is off', function () {
    $job = $this->lifecycle->createFromIntake(calibrationIntake(), $this->actor);
    $job->forceFill(['is_insurance' => true, 'needs_calibration' => false, 'glass_position' => 'Windshield'])->save();

    expect($job->needs_calibration)->toBeFalse()
        ->and($job->needsCalibration())->toBeTrue();
});

it('treats the rep shorthand "WS" as a windshield', function (string $position) {
    $job = $this->lifecycle->createFromIntake(
        calibrationIntake(['is_insurance' => true, 'glass_position' => $position]),
        $this->actor,
    );

    expect($job->needsCalibration())->toBeTrue();
})->with([['WS'], ['W/S'], ['windshield'], ['Windscreen'], ['WS - chip']]);
