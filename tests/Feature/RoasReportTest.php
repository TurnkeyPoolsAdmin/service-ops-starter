<?php

use App\Models\AdMetric;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\Job;
use App\Services\Reporting\RoasReport;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;

beforeEach(function () {
    $this->seed([EntitySeeder::class]);
    $this->la = Entity::where('code', 'LA')->first();
    $this->sfv = Entity::where('code', 'SFV')->first();
    $this->customer = Customer::create(['phone_e164' => '+18185551234', 'first_name' => 'Al']);
});

/** Create a job for $entity booked (created) at a given Pacific datetime, with a quote. */
function bookedJob($entity, $customer, string $bookedAtPacific, int $quote, int $partCost = 0, int $techPay = 0, string $status = 'completed'): Job
{
    static $n = 0;
    $n++;

    return Job::create([
        'job_number' => $entity->code.'-2026-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'entity_id' => $entity->id,
        'customer_id' => $customer->id,
        'status' => $status,
        'quote_amount_cents' => $quote,
        'part_cost_cents' => $partCost,
        'tech_pay_amount_cents' => $techPay,
        // Booked revenue is attributed to the week the job was created.
        'created_at' => Carbon::parse($bookedAtPacific, 'America/Los_Angeles')->utc(),
    ]);
}

function adDay($entity, string $date, int $costMicros, float $conversions): AdMetric
{
    return AdMetric::create([
        'entity_id' => $entity->id,
        'date' => $date,
        'cost_micros' => $costMicros,
        'conversions' => $conversions,
    ]);
}

it('aggregates spend, leads, revenue and profit into the right Monday-start week', function () {
    // Week of Mon Jun 15 – Sun Jun 21, 2026.
    adDay($this->la, '2026-06-15', 100_000_000, 4);   // $100, 4 leads
    adDay($this->la, '2026-06-17', 50_000_000, 1);    // $50, 1 lead  -> week: $150, 5 leads
    // A job booked that week: quote $900, part $200, tech $300 -> booked profit $400.
    bookedJob($this->la, $this->customer, '2026-06-18 14:00', 90000, 20000, 30000);

    $report = app(RoasReport::class)->build(
        Carbon::parse('2026-06-15', 'America/Los_Angeles'),
        Carbon::parse('2026-06-21', 'America/Los_Angeles'),
        [$this->la->id],
    );

    expect($report['weeks'])->toHaveCount(1);
    $week = $report['locations'][0]['byWeek']['2026-06-15'];

    expect($week->spendCents)->toBe(15000)          // $150
        ->and($week->leads)->toBe(5.0)
        ->and($week->costPerLeadCents())->toBe(3000) // $150 / 5 = $30
        ->and($week->revenueCents)->toBe(90000)      // $900
        ->and($week->profitCents)->toBe(40000)       // $400
        ->and($week->roas())->toBe(6.0)              // 90000 / 15000
        ->and($week->netCents())->toBe(25000);       // profit 40000 - spend 15000
});

it('counts a booked-but-unpaid job, excluding lost/canceled bookings', function () {
    adDay($this->la, '2026-06-16', 10_000_000, 1);
    // Pending (never paid) job still counts as booked revenue by its quote.
    bookedJob($this->la, $this->customer, '2026-06-16 09:00', 68200, status: 'pending_schedule');
    // A booking that fell through must NOT count.
    bookedJob($this->la, $this->customer, '2026-06-16 11:00', 50000, status: 'lost');

    $report = app(RoasReport::class)->build(
        Carbon::parse('2026-06-15', 'America/Los_Angeles'),
        Carbon::parse('2026-06-21', 'America/Los_Angeles'),
        [$this->la->id],
    );

    expect($report['locations'][0]['byWeek']['2026-06-15']->revenueCents)->toBe(68200);
});

it('splits metrics across week boundaries and totals them per location', function () {
    // Week 1 (Jun 15–21) and Week 2 (Jun 22–28).
    adDay($this->la, '2026-06-16', 20_000_000, 2);    // wk1: $20, 2
    adDay($this->la, '2026-06-23', 30_000_000, 3);    // wk2: $30, 3
    bookedJob($this->la, $this->customer, '2026-06-16 10:00', 50000);  // wk1 revenue $500
    bookedJob($this->la, $this->customer, '2026-06-24 10:00', 70000);  // wk2 revenue $700

    $report = app(RoasReport::class)->build(
        Carbon::parse('2026-06-15', 'America/Los_Angeles'),
        Carbon::parse('2026-06-28', 'America/Los_Angeles'),
        [$this->la->id],
    );

    expect($report['weeks'])->toHaveCount(2);
    $loc = $report['locations'][0];

    expect($loc['byWeek']['2026-06-15']->spendCents)->toBe(2000)
        ->and($loc['byWeek']['2026-06-15']->revenueCents)->toBe(50000)
        ->and($loc['byWeek']['2026-06-22']->spendCents)->toBe(3000)
        ->and($loc['byWeek']['2026-06-22']->revenueCents)->toBe(70000)
        ->and($loc['total']->spendCents)->toBe(5000)
        ->and($loc['total']->leads)->toBe(5.0)
        ->and($loc['total']->revenueCents)->toBe(120000);

    // Grand total equals the single location's total here.
    expect($report['grand']->spendCents)->toBe(5000)
        ->and($report['grand']->revenueCents)->toBe(120000);
});

it('returns null ratios instead of dividing by zero', function () {
    // Spend but no leads, and a paid job but no spend.
    adDay($this->la, '2026-06-15', 40_000_000, 0);            // spend, 0 leads
    bookedJob($this->la, $this->customer, '2026-06-15 10:00', 60000);

    $report = app(RoasReport::class)->build(
        Carbon::parse('2026-06-15', 'America/Los_Angeles'),
        Carbon::parse('2026-06-21', 'America/Los_Angeles'),
        [$this->la->id],
    );

    $week = $report['locations'][0]['byWeek']['2026-06-15'];
    expect($week->costPerLeadCents())->toBeNull()   // 0 leads
        ->and($week->roas())->toBe(15.0);           // 60000 / 4000

    // A location with no spend at all -> ROAS null.
    $sfvReport = app(RoasReport::class)->build(
        Carbon::parse('2026-06-15', 'America/Los_Angeles'),
        Carbon::parse('2026-06-21', 'America/Los_Angeles'),
        [$this->sfv->id],
    );
    expect($sfvReport['locations'][0]['byWeek']['2026-06-15']->roas())->toBeNull();
});

it('only includes the requested locations, defaulting to all active', function () {
    adDay($this->la, '2026-06-15', 10_000_000, 1);
    adDay($this->sfv, '2026-06-15', 20_000_000, 2);

    $all = app(RoasReport::class)->build(
        Carbon::parse('2026-06-15', 'America/Los_Angeles'),
        Carbon::parse('2026-06-21', 'America/Los_Angeles'),
    );
    // EntitySeeder creates 4 active locations (LA, SFV, DelMar, PHX).
    expect($all['locations'])->toHaveCount(4)
        ->and($all['grand']->spendCents)->toBe(3000);

    $justLa = app(RoasReport::class)->build(
        Carbon::parse('2026-06-15', 'America/Los_Angeles'),
        Carbon::parse('2026-06-21', 'America/Los_Angeles'),
        [$this->la->id],
    );
    expect($justLa['locations'])->toHaveCount(1)
        ->and($justLa['grand']->spendCents)->toBe(1000);
});
