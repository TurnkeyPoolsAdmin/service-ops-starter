<?php

use App\Enums\IssueType;
use App\Filament\Pages\Intake;
use App\Filament\Resources\Jobs\Pages\ViewJob;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Issue;
use App\Models\Job;
use App\Models\JobNote;
use App\Services\JobLifecycle;
use App\Support\PartNumberGlass;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');
    $this->actingAs($this->actor);

    $this->tech = Employee::create([
        'name' => 'Jose', 'email' => 'jose@test.local', 'active' => true,
        'job_functions' => ['technician'], 'entity_id' => Entity::where('code', 'LA')->value('id'),
    ]);

    $this->lifecycle = app(JobLifecycle::class);
});

function glassJob(array $overrides = []): Job
{
    return app(JobLifecycle::class)->createFromIntake(array_merge([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Pat',
        'service_address' => '1 Main St, Sylmar',
        'glass_position' => 'Front Left Door',
        'part_number' => 'FW02435 GBN FYG',
        'parts_json' => [['label' => 'FW02435 GBN FYG', 'cost_cents' => 15000]],
        'quote_amount_cents' => 40000,
        'intake_raw_text' => 'raw',
    ], $overrides), auth()->user());
}

// ---- The reader ----

it('reads the glass letter out of a NAGS part number', function (string $part, ?string $code) {
    expect(PartNumberGlass::codeForPartNumber($part))->toBe($code);
})->with([
    ['FW02435 GBN FYG', 'W'],
    ['DW2853GTY', 'W'],
    ['fd12345', 'D'],
    ['FB0987 GTY', 'B'],
    ['DQ4455', 'Q'],
    ['FV1122', 'V'],
    ['DR3344', 'R'],
    // No opinion: free text, a dealer SKU, a letter pair we don't know, no digits.
    ['Windshield for a Civic', null],
    ['ABC1234', null],
    ['FX02435', null],
    ['FW', null],
    ['', null],
]);

it('maps every canonical glass position to a code, and all four doors to the same one', function (string $position, ?string $code) {
    expect(PartNumberGlass::codeForPosition($position))->toBe($code);
})->with([
    ['Windshield', 'W'],
    ['Back Glass', 'B'],
    ['Front Left Door', 'D'],
    ['Front Right Door', 'D'],
    ['Rear Left Door', 'D'],
    ['Rear Right Door', 'D'],
    ['Front Left Vent Glass', 'V'],
    ['Rear Right Quarter Glass', 'Q'],
    ['Sunroof', 'R'],
    // Rep shorthand / legacy free text still reads.
    ['W/S', 'W'],
    ['WS', 'W'],
    // Nothing recognizable — no opinion, so never a mismatch.
    ['Left side', null],
]);

it('never claims a mismatch when either side is unreadable', function () {
    // Unreadable part number.
    expect(PartNumberGlass::mismatch(['Some glass'], 'Front Left Door'))->toBeNull();
    // Unreadable position.
    expect(PartNumberGlass::mismatch(['FW02435'], 'Left side'))->toBeNull();
    // Both blank.
    expect(PartNumberGlass::mismatch([null], null))->toBeNull();
});

it('accepts a multi-part job as soon as ONE part matches the position', function () {
    expect(PartNumberGlass::mismatch(['FW02435', 'FD11223'], 'Front Left Door'))->toBeNull()
        ->and(PartNumberGlass::mismatch(['FW02435', 'FB11223'], 'Front Left Door'))->not->toBeNull();
});

// ---- On the job ----

it('flags a windshield part number on a door-glass job', function () {
    $job = glassJob();

    expect($job->hasPartGlassMismatch())->toBeTrue()
        ->and($job->hasUnresolvedPartGlassMismatch())->toBeTrue()
        ->and($job->partGlassMismatch()['part_code'])->toBe('W')
        ->and($job->partGlassMismatch()['position_code'])->toBe('D');
});

it('does not flag a job whose part number agrees with the position', function () {
    $job = glassJob(['glass_position' => 'Windshield']);

    expect($job->hasPartGlassMismatch())->toBeFalse()
        ->and($job->hasUnresolvedPartGlassMismatch())->toBeFalse();
});

it('never flags a labor-only job — the glass is the customer\'s', function () {
    $job = glassJob(['labor_only' => true]);

    expect($job->hasPartGlassMismatch())->toBeFalse();
});

// ---- Intake warns but never blocks ----

it('creates the job anyway when the part number and glass position disagree', function () {
    $rep = Employee::create([
        'name' => 'Marcel', 'email' => 'marcel@test.local', 'active' => true, 'job_functions' => ['sales'],
    ]);

    Livewire::test(Intake::class)
        ->set('data.entity_id', Entity::where('code', 'LA')->value('id'))
        ->set('data.booking_salesperson_id', $rep->id)
        ->set('data.phone_e164', '+18185559999')
        ->set('data.first_name', 'Dana')
        ->set('data.service_address', '2 Main St, Sylmar')
        ->set('data.glass_position', 'Rear Left Door')
        ->set('data.parts', [['label' => 'FW02435 GBN FYG', 'cost' => null]])
        ->call('create')
        ->assertHasNoErrors();

    $job = Job::where('customer_phone_snapshot', '+18185559999')->firstOrFail();

    expect($job->exists)->toBeTrue()
        ->and($job->hasUnresolvedPartGlassMismatch())->toBeTrue();
});

// ---- Scheduling: resolve or override ----

it('blocks scheduling while the mismatch is unresolved and not overridden', function () {
    $job = glassJob();

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->mountAction('schedule')
        ->setActionData([
            'scheduled_date' => now()->addDays(2)->format('Y-m-d'),
            'scheduled_time' => '09:00',
            'time_phrasing' => 'window',
            'technician_id' => $this->tech->id,
            'warehouse_id' => 'other',
            'warehouse_other' => 'Dealer',
            'part_glass_position' => 'Front Left Door',
            'part_glass_number' => 'FW02435 GBN FYG',
            'part_glass_override' => false,
        ])
        ->callMountedAction()
        ->assertHasActionErrors(['part_glass_override']);

    expect($job->refresh()->scheduled_at)->toBeNull();
});

it('schedules once the position is corrected in the modal — no override, no issue', function () {
    $job = glassJob();

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->mountAction('schedule')
        ->setActionData([
            'scheduled_date' => now()->addDays(2)->format('Y-m-d'),
            'scheduled_time' => '09:00',
            'time_phrasing' => 'window',
            'technician_id' => $this->tech->id,
            'warehouse_id' => 'other',
            'warehouse_other' => 'Dealer',
            'part_glass_position' => 'Windshield',
            'part_glass_number' => 'FW02435 GBN FYG',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $job->refresh();

    expect($job->scheduled_at)->not->toBeNull()
        ->and($job->glass_position)->toBe('Windshield')
        ->and($job->hasPartGlassMismatch())->toBeFalse()
        ->and(Issue::where('type', IssueType::PartGlassMismatch->value)->count())->toBe(0);
});

it('correcting the part number rewrites parts_json in place, keeping the cost', function () {
    $job = glassJob();

    $this->lifecycle->correctPartGlass($job, null, 'FD99887 GTY', $this->actor);

    $job->refresh();

    expect($job->part_number)->toBe('FD99887 GTY')
        ->and($job->parts_json[0]['label'])->toBe('FD99887 GTY')
        ->and($job->parts_json[0]['cost_cents'])->toBe(15000)
        ->and($job->hasPartGlassMismatch())->toBeFalse();
});

it('schedules on an override, records the note, and raises a manager issue', function () {
    $job = glassJob();

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->mountAction('schedule')
        ->setActionData([
            'scheduled_date' => now()->addDays(2)->format('Y-m-d'),
            'scheduled_time' => '09:00',
            'time_phrasing' => 'window',
            'technician_id' => $this->tech->id,
            'warehouse_id' => 'other',
            'warehouse_other' => 'Dealer',
            'part_glass_position' => 'Front Left Door',
            'part_glass_number' => 'FW02435 GBN FYG',
            'part_glass_override' => true,
            'part_glass_override_note' => 'Sales confirmed the part number is right.',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $job->refresh();

    $issue = Issue::where('type', IssueType::PartGlassMismatch->value)->first();

    expect($job->scheduled_at)->not->toBeNull()
        ->and($job->part_glass_override_at)->not->toBeNull()
        ->and($job->part_glass_override_by)->toBe($this->actor->id)
        ->and($job->hasUnresolvedPartGlassMismatch())->toBeFalse()
        ->and($job->hasOverriddenPartGlassMismatch())->toBeTrue()
        ->and($issue)->not->toBeNull()
        ->and($issue->subject_id)->toBe($job->id)
        ->and($issue->details['override_note'])->toBe('Sales confirmed the part number is right.')
        ->and(JobNote::where('job_id', $job->id)->count())->toBe(1);
});

it('re-prompts when the job is edited into a DIFFERENT mismatch', function () {
    $job = glassJob();
    $this->lifecycle->overridePartGlassMismatch($job, 'Confirmed with sales.', $this->actor);

    expect($job->refresh()->hasUnresolvedPartGlassMismatch())->toBeFalse();

    // Same job, new disagreement — the old excuse does not carry over.
    $job->update(['glass_position' => 'Back Glass']);

    expect($job->refresh()->hasUnresolvedPartGlassMismatch())->toBeTrue();
});

// ---- The sweep only heals ----

it('clears the issue once the part or position is corrected', function () {
    $job = glassJob();
    $this->lifecycle->overridePartGlassMismatch($job, 'Confirmed with sales.', $this->actor);

    expect(Issue::where('type', IssueType::PartGlassMismatch->value)->open()->count())->toBe(1);

    // Somebody fixes the position on the job page.
    $job->update(['glass_position' => 'Windshield']);

    $this->artisan('issues:sweep')->assertSuccessful();

    expect(Issue::where('type', IssueType::PartGlassMismatch->value)->open()->count())->toBe(0);
});

it('leaves a still-mismatched overridden job on the queue', function () {
    $job = glassJob();
    $this->lifecycle->overridePartGlassMismatch($job, 'Confirmed with sales.', $this->actor);

    $this->artisan('issues:sweep')->assertSuccessful();

    expect(Issue::where('type', IssueType::PartGlassMismatch->value)->open()->count())->toBe(1);
});
