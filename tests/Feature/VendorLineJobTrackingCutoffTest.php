<?php

use App\Models\Customer;
use App\Models\Entity;
use App\Models\Job;
use App\Models\VendorDocumentLine;
use App\Services\Vendors\VendorLineMatcher;
use Database\Seeders\EntitySeeder;

/**
 * Vendor lines dated before Job records existed can never find a match — there
 * is no job old enough. Without a cutoff they sit in "Lines to match" forever
 * looking like a broken matcher. These cover the boundary itself; the matching
 * logic proper is covered by MygrantLineMatchingTest.
 */
beforeEach(function () {
    test()->seed(EntitySeeder::class);

    $this->customer = Customer::firstOrCreate(['phone_e164' => '+18185551234'], ['first_name' => 'Test']);
    $this->entityId = Entity::where('code', 'LA')->value('id');
    $this->matcher = app(VendorLineMatcher::class);

    config(['glass.vendors.job_tracking_start' => '2026-06-26']);
});

function cutoffJob(array $overrides = []): Job
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
        'created_at' => '2026-07-01 12:00:00',
    ], $overrides));
}

function cutoffLine(array $overrides = []): VendorDocumentLine
{
    return VendorDocumentLine::create(array_merge([
        'supplier' => 'mygrant',
        'order_number' => 'S1000000-0',
        'order_line' => 'S1000000-'.(VendorDocumentLine::count() + 1),
        'order_date' => '2026-07-01',
        'confirmed_date' => '2026-07-01',
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

it('files a line dated before the cutoff as pre_job_tracking and drops it out of the queue', function () {
    // A November 2025 invoice line: real, billed, and unmatchable by definition.
    $line = cutoffLine(['order_date' => '2025-11-14', 'confirmed_date' => '2025-11-14']);

    expect(VendorDocumentLine::needsJob()->pluck('id'))->toContain($line->id);

    expect($this->matcher->matchOne($line->fresh()))->toBe('no_job');

    $line->refresh();
    expect($line->match_status)->toBe(VendorDocumentLine::MATCH_NO_JOB)
        ->and($line->no_job_reason)->toBe('pre_job_tracking')
        ->and($line->match_source)->toBe('auto')
        ->and($line->job_id)->toBeNull()
        ->and($line->suggested_job_id)->toBeNull();

    // The whole point: it stops cluttering "Lines to match".
    expect(VendorDocumentLine::needsJob()->pluck('id'))->not->toContain($line->id);
});

it('leaves a line dated after the cutoff to match normally', function () {
    $job = cutoffJob(['parts_ordered_at' => '2026-07-01 12:00:00']);
    $line = cutoffLine();

    expect($this->matcher->matchOne($line->fresh()))->toBe('matched');

    $line->refresh();
    expect($line->job_id)->toBe($job->id)
        ->and($line->no_job_reason)->toBeNull();
});

it('changes nothing when the cutoff is not configured', function () {
    config(['glass.vendors.job_tracking_start' => null]);

    // Same 2025 line as the first test — with no cutoff set it must fall
    // through to the ordinary path, not be excluded.
    $line = cutoffLine(['order_date' => '2025-11-14', 'confirmed_date' => '2025-11-14']);

    $result = $this->matcher->matchOne($line->fresh());

    $line->refresh();
    expect($line->no_job_reason)->not->toBe('pre_job_tracking');
    expect($result)->not->toBe('no_job');
});

it('never treats a line with no date at all as pre-tracking', function () {
    $line = cutoffLine(['order_date' => null, 'confirmed_date' => null]);

    $this->matcher->matchOne($line->fresh());

    $line->refresh();
    expect($line->no_job_reason)->not->toBe('pre_job_tracking');
});
