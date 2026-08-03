<?php

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\VendorDocumentLine;
use App\Services\Vendors\VendorLineMatcher;
use Database\Seeders\EntitySeeder;

/**
 * Covers App\Services\Vendors\VendorLineMatcher: consumable routing, the
 * strong auto-link bar (part + vehicle + date, unique), the weaker suggestion
 * tier, amount/tech as confirm-only signals, and the part-cost fill/never-
 * overwrite + variance-flagging rules the owner requires.
 */
beforeEach(function () {
    test()->seed(EntitySeeder::class);

    $this->customer = Customer::firstOrCreate(['phone_e164' => '+18185551234'], ['first_name' => 'Test']);
    $this->entityId = Entity::where('code', 'LA')->value('id');

    $this->matcher = app(VendorLineMatcher::class);
});

function mygrantJob(array $overrides = []): Job
{
    return Job::create(array_merge([
        'job_number' => 'LA-2026-'.str_pad((string) (Job::count() + 1), 4, '0', STR_PAD_LEFT),
        'entity_id' => test()->entityId,
        'customer_id' => test()->customer->id,
        'customer_name_snapshot' => 'Test Customer',
        'status' => 'completed',
        'part_number' => 'FD24400',
        'vehicle_year' => '2020',
        'vehicle_make' => 'Honda',
        'vehicle_model' => 'Accord',
        'created_at' => '2026-07-25 12:00:00',
    ], $overrides));
}

function makeLine(array $overrides = []): VendorDocumentLine
{
    return VendorDocumentLine::create(array_merge([
        'supplier' => 'mygrant',
        'order_number' => 'S1000000-0',
        'order_line' => 'S1000000-'.(VendorDocumentLine::count() + 1),
        'order_date' => '2026-07-25',
        'confirmed_date' => '2026-07-25',
        'part_number' => 'FD24400 GTY FYG',
        'part_number_base' => 'FD24400',
        'part_description' => '19-25 Honda Accord 4D Sedan F/L (Slr)',
        'vehicle_years_from' => 2019,
        'vehicle_years_to' => 2025,
        'vehicle_make' => 'Honda',
        'vehicle_model' => 'Accord',
        'quantity_ordered' => 1,
        'quantity_delivered' => 1,
        'unit_price_cents' => 18500,
        'line_total_cents' => 18500,
        'status' => 'billed',
        'match_status' => VendorDocumentLine::MATCH_UNMATCHED,
    ], $overrides));
}

// ---- Consumables ----

it('auto-routes a consumable (no parseable vehicle, under the threshold) to no_job / shop_supplies', function () {
    $line = makeLine([
        'part_number' => 'WKT F2000 GOLD',
        'part_number_base' => 'WKTF2000',
        'part_description' => 'Windshield urethane kit',
        'vehicle_years_from' => null,
        'vehicle_years_to' => null,
        'vehicle_make' => null,
        'vehicle_model' => null,
        'line_total_cents' => 2700,
    ]);

    $state = $this->matcher->matchOne($line);

    expect($state)->toBe('no_job')
        ->and($line->refresh()->match_status)->toBe(VendorDocumentLine::MATCH_NO_JOB)
        ->and($line->no_job_reason)->toBe('shop_supplies')
        ->and($line->match_source)->toBe('auto');
});

it('leaves a no-vehicle line ABOVE the consumable threshold for human review (the $775 case)', function () {
    $line = makeLine([
        'part_number' => 'MISC-EXPENSIVE',
        'part_number_base' => 'MISCEXPENSIVE',
        'part_description' => 'Some expensive shop item',
        'vehicle_years_from' => null,
        'vehicle_years_to' => null,
        'vehicle_make' => null,
        'vehicle_model' => null,
        'line_total_cents' => 77500, // $775 > $100 default consumable_max_cents
    ]);

    $state = $this->matcher->matchOne($line);

    expect($state)->toBeNull()
        ->and($line->refresh()->match_status)->toBe(VendorDocumentLine::MATCH_UNMATCHED);
});

// ---- Auto-link bar: part + vehicle + date, unique ----

it('auto-links when part number matches, vehicle agrees, and exactly one job qualifies', function () {
    $job = mygrantJob();
    $line = makeLine();

    $state = $this->matcher->matchOne($line);

    expect($state)->toBe('matched')
        ->and($line->refresh()->job_id)->toBe($job->id)
        ->and($line->match_status)->toBe(VendorDocumentLine::MATCH_MATCHED)
        ->and($line->match_source)->toBe('auto');
});

it('does not auto-link when two jobs both satisfy part + vehicle (ambiguous is not a match)', function () {
    mygrantJob(['job_number' => 'LA-2026-0001']);
    mygrantJob(['job_number' => 'LA-2026-0002']);
    $line = makeLine();

    $state = $this->matcher->matchOne($line);

    // Both are equally eligible for the suggestion tier too (identical score),
    // so it stays unmatched — a tie is never resolved by guessing.
    expect($state)->toBeNull()
        ->and($line->refresh()->match_status)->toBe(VendorDocumentLine::MATCH_UNMATCHED);
});

it('does not auto-link a job outside the match window even with matching part + vehicle', function () {
    mygrantJob(['created_at' => '2026-06-01 12:00:00']); // ~55 days before the line's date
    $line = makeLine(['confirmed_date' => '2026-07-25', 'order_date' => '2026-07-25']);

    $state = $this->matcher->matchOne($line);

    expect($state)->toBeNull();
});

// ---- Amount alone never links ----

it('never links (or even suggests) on amount agreement alone, with no part or vehicle signal', function () {
    mygrantJob([
        'part_number' => 'ZZZ99999', // does not match the line's part
        'vehicle_make' => 'Toyota',  // does not match the line's vehicle
        'vehicle_model' => 'Camry',
        'vehicle_year' => '2022',
        'part_cost_cents' => 18500, // exact dollar match to the line — irrelevant on its own
    ]);
    $line = makeLine(['line_total_cents' => 18500]);

    $state = $this->matcher->matchOne($line);

    expect($state)->toBeNull()
        ->and($line->refresh()->suggested_job_id)->toBeNull();
});

// ---- Suggestion tier: partial signal, unique -> suggested (never auto) ----

it('suggests (does not auto-link) when only the part number matches and the vehicle disagrees', function () {
    $job = mygrantJob(['vehicle_make' => 'Toyota', 'vehicle_model' => 'Camry', 'vehicle_year' => '2022']);
    $line = makeLine(); // part FD24400 matches; vehicle (Honda Accord) does not

    $state = $this->matcher->matchOne($line);

    expect($state)->toBe('suggested')
        ->and($line->refresh()->suggested_job_id)->toBe($job->id)
        ->and($line->match_status)->toBe(VendorDocumentLine::MATCH_SUGGESTED)
        ->and($line->job_id)->toBeNull(); // not linked yet — needs a human confirm
});

it('suggests when only the vehicle agrees and the part number differs', function () {
    $job = mygrantJob(['part_number' => 'ZZZ99999']);
    $line = makeLine(); // vehicle agrees (Honda Accord 2020 within 2019-2025); part differs

    $state = $this->matcher->matchOne($line);

    expect($state)->toBe('suggested')
        ->and($line->refresh()->suggested_job_id)->toBe($job->id);
});

it('amount agreement confirms and tech name breaks a tie between two partial-signal candidates', function () {
    $tech = Employee::create([
        'name' => 'Jose Martinez', 'email' => 'jose@test.local', 'can_login' => false, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['technician'],
    ]);

    // Both jobs match on VEHICLE only (part numbers differ from the line's);
    // identical partial score EXCEPT job A also has the tech's name in the
    // line's Customer_PO and an amount within 10% — those bonuses break the tie.
    $jobA = mygrantJob(['job_number' => 'LA-2026-0001', 'part_number' => 'AAA1111', 'part_cost_cents' => 18000, 'technician_id' => $tech->id]);
    mygrantJob(['job_number' => 'LA-2026-0002', 'part_number' => 'BBB2222']);

    $line = makeLine(['customer_po' => ': JOSE', 'line_total_cents' => 18500]);

    $state = $this->matcher->matchOne($line);

    expect($state)->toBe('suggested')
        ->and($line->refresh()->suggested_job_id)->toBe($jobA->id);
});

it('leaves it unmatched when two partial-signal candidates tie with no tiebreaker', function () {
    mygrantJob(['job_number' => 'LA-2026-0001', 'part_number' => 'ZZZ99999']); // vehicle-only match
    mygrantJob(['job_number' => 'LA-2026-0002', 'part_number' => 'YYY88888']); // vehicle-only match, identical score
    $line = makeLine();

    $state = $this->matcher->matchOne($line);

    expect($state)->toBeNull();
});

// ---- Candidate exclusion: same part, already linked ----

it('excludes a job already linked to another billed line for the SAME part, but allows a different part', function () {
    $job = mygrantJob();
    $firstLine = makeLine(['order_line' => 'S1-first']);
    expect($this->matcher->matchOne($firstLine))->toBe('matched');

    // A second line for the SAME part number must not re-offer this job.
    $secondLine = makeLine(['order_line' => 'S1-second']);
    expect($this->matcher->matchOne($secondLine))->toBeNull();

    // A DIFFERENT part on the same job's date window is still a fair candidate.
    $thirdLine = makeLine([
        'order_line' => 'S1-third',
        'part_number' => 'URETHANE-1',
        'part_number_base' => 'URETHANE',
    ]);
    // The job's part_number doesn't match "URETHANE" so this only reaches the
    // vehicle-agreement suggestion tier, not auto-match — still proves the
    // job wasn't hard-excluded outright.
    expect($this->matcher->matchOne($thirdLine))->toBe('suggested');
});

// ---- Part cost: never overwrite, blank IS filled, variance flags any difference ----

it('fills a genuinely blank job part cost from the invoice line, with zero variance', function () {
    $job = mygrantJob(['part_cost_cents' => null]);
    $line = makeLine(['line_total_cents' => 18500]);

    $this->matcher->matchOne($line);

    expect($job->refresh()->part_cost_cents)->toBe(18500)
        ->and($line->refresh()->variance_cents)->toBe(0);
});

it('NEVER overwrites a part cost a human already typed, even when the invoice differs', function () {
    $job = mygrantJob(['part_cost_cents' => 20000]); // human typed $200
    $line = makeLine(['line_total_cents' => 18500]); // invoice says $185

    $this->matcher->matchOne($line);

    expect($job->refresh()->part_cost_cents)->toBe(20000) // untouched
        ->and($line->refresh()->variance_cents)->toBe(-1500); // flagged: invoice - typed
});

it('flags variance for ANY non-zero difference, no matter how small', function () {
    $job = mygrantJob(['part_cost_cents' => 18501]); // one cent off
    $line = makeLine(['line_total_cents' => 18500]);

    $this->matcher->matchOne($line);

    expect($line->refresh()->variance_cents)->toBe(-1);
});

it('records zero variance when the invoice agrees exactly with an already-typed cost', function () {
    $job = mygrantJob(['part_cost_cents' => 18500]);
    $line = makeLine(['line_total_cents' => 18500]);

    $this->matcher->matchOne($line);

    expect($job->refresh()->part_cost_cents)->toBe(18500)
        ->and($line->refresh()->variance_cents)->toBe(0);
});

// ---- Human actions ----

it('confirmSuggestion links the suggested job and applies the cost/variance rules', function () {
    $job = mygrantJob(['vehicle_make' => 'Toyota', 'vehicle_model' => 'Camry', 'vehicle_year' => '2022', 'part_cost_cents' => null]);
    $line = makeLine(['line_total_cents' => 18500]); // part matches only

    $this->matcher->matchOne($line);
    expect($line->refresh()->match_status)->toBe(VendorDocumentLine::MATCH_SUGGESTED);

    $actor = Employee::create([
        'name' => 'Admin', 'email' => 'admin@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->matcher->confirmSuggestion($line, $actor);

    expect($line->refresh()->job_id)->toBe($job->id)
        ->and($line->match_status)->toBe(VendorDocumentLine::MATCH_MATCHED)
        ->and($line->match_source)->toBe('manual')
        ->and($line->matched_by)->toBe($actor->id)
        ->and($job->refresh()->part_cost_cents)->toBe(18500);
});

it('markNoJob lets a human close out a line with no job, any reason', function () {
    $line = makeLine();

    $this->matcher->markNoJob($line, 'other');

    expect($line->refresh()->match_status)->toBe(VendorDocumentLine::MATCH_NO_JOB)
        ->and($line->no_job_reason)->toBe('other')
        ->and($line->match_source)->toBe('manual');
});
