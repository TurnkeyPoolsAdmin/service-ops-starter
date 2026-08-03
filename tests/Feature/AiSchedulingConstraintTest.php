<?php

use App\Enums\Language;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\Job;
use App\Services\AiScheduling\RequestedSlotParser;
use App\Services\AiScheduling\SchedulingConstraint;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);
    Carbon::setTestNow(Carbon::parse('2026-07-20 09:00', 'America/Los_Angeles')); // a Monday
});

afterEach(fn () => Carbon::setTestNow());

/** A minimal job with a given requested day / time-preference / free text, for constraint parsing. */
function constraintJob(?string $requestedDay = null, ?string $preference = null, ?string $requestedTime = null): Job
{
    $customer = Customer::create(['phone_e164' => '+1818555'.random_int(1000, 9999)]);

    return Job::create([
        'job_number' => 'LA-2026-'.random_int(10000, 99999),
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customer->id,
        'requested_day' => $requestedDay,
        'requested_time_preference' => $preference,
        'requested_time' => $requestedTime,
    ]);
}

// ---- RequestedSlotParser::extractClockTime ----

it('extracts an explicit am/pm clock time', function () {
    expect(RequestedSlotParser::extractClockTime('2pm'))->toBe('14:00');
    expect(RequestedSlotParser::extractClockTime('10:30am'))->toBe('10:30');
    expect(RequestedSlotParser::extractClockTime('8 AM'))->toBe('08:00');
});

it('guesses meridiem for a bare hour from shop-hours heuristics', function () {
    expect(RequestedSlotParser::extractClockTime('after 3'))->toBe('15:00');
    expect(RequestedSlotParser::extractClockTime('a las 3'))->toBe('15:00');
    expect(RequestedSlotParser::extractClockTime('10'))->toBe('10:00');
    expect(RequestedSlotParser::extractClockTime('12'))->toBe('12:00');
});

it('returns null for text with no clock time at all', function () {
    expect(RequestedSlotParser::extractClockTime('whenever works'))->toBeNull();
    expect(RequestedSlotParser::extractClockTime(''))->toBeNull();
    expect(RequestedSlotParser::extractClockTime(null))->toBeNull();
});

// ---- RequestedSlotParser::parse ----

it('parses a morning preference into a window constraint', function () {
    $job = constraintJob('2026-07-22', 'morning');
    $constraint = (new RequestedSlotParser)->parse($job);

    expect($constraint->date)->toBe('2026-07-22')
        ->and($constraint->firmness)->toBe(SchedulingConstraint::FIRM_WINDOW)
        ->and($constraint->partOfDay)->toBe('morning')
        ->and($constraint->earliest)->toBe('08:00')
        ->and($constraint->latest)->toBe('12:00');
});

it('parses ASAP / no preference into a flexible constraint', function () {
    $constraint = (new RequestedSlotParser)->parse(constraintJob('2026-07-22', 'asap'));
    expect($constraint->firmness)->toBe(SchedulingConstraint::FIRM_FLEXIBLE);

    $constraint = (new RequestedSlotParser)->parse(constraintJob('2026-07-22', null));
    expect($constraint->firmness)->toBe(SchedulingConstraint::FIRM_FLEXIBLE);
});

it('parses a specific preference with a parseable clock time into an exact constraint', function () {
    $job = constraintJob('2026-07-22', 'specific', '2pm');
    $constraint = (new RequestedSlotParser)->parse($job);

    expect($constraint->firmness)->toBe(SchedulingConstraint::FIRM_EXACT)
        ->and($constraint->exactTime)->toBe('14:00');
});

it('falls back to flexible when specific has no parseable time and no LLM fallback is wired', function () {
    $job = constraintJob('2026-07-22', 'specific', 'right after school');
    $constraint = (new RequestedSlotParser)->parse($job);

    expect($constraint->firmness)->toBe(SchedulingConstraint::FIRM_FLEXIBLE);
});

it('defaults to the next business day when no requested day was ever given', function () {
    // "now" is Monday 2026-07-20 -> next business day is Tuesday 2026-07-21.
    $constraint = (new RequestedSlotParser)->parse(constraintJob(null, null));
    expect($constraint->date)->toBe('2026-07-21');
});

it('treats legacy free-text requested_day (unparseable) the same as no date', function () {
    $constraint = (new RequestedSlotParser)->parse(constraintJob('next Fri', 'morning'));
    expect($constraint->date)->toBe('2026-07-21');
});

// ---- SchedulingConstraint::admits ----

it('an exact constraint admits only a matching date and a close-enough time', function () {
    $c = SchedulingConstraint::exact('2026-07-22', '14:00');

    expect($c->admits('2026-07-22', '14:00'))->toBeTrue()
        ->and($c->admits('2026-07-22', '14:30'))->toBeFalse()
        ->and($c->admits('2026-07-23', '14:00'))->toBeFalse()
        ->and($c->admits('2026-07-22', null))->toBeTrue(); // no time named = they just agreed
});

it('a window constraint admits any time inside the window, on the right date', function () {
    $c = SchedulingConstraint::window('2026-07-22', 'morning');

    expect($c->admits('2026-07-22', '09:00'))->toBeTrue()
        ->and($c->admits('2026-07-22', '08:00'))->toBeTrue()
        ->and($c->admits('2026-07-22', '12:00'))->toBeTrue()
        ->and($c->admits('2026-07-22', '13:00'))->toBeFalse()
        ->and($c->admits('2026-07-23', '09:00'))->toBeFalse();
});

it('a flexible constraint admits any time within the configured business day span', function () {
    $c = SchedulingConstraint::flexible('2026-07-22'); // day_parts span 08:00-19:00

    expect($c->admits('2026-07-22', '08:30'))->toBeTrue()
        ->and($c->admits('2026-07-22', '18:30'))->toBeTrue()
        ->and($c->admits('2026-07-22', '07:00'))->toBeFalse()
        ->and($c->admits('2026-07-23', '10:00'))->toBeFalse();
});

it('preferredTime falls back to the window start when nothing more specific was named', function () {
    expect(SchedulingConstraint::window('2026-07-22', 'afternoon')->preferredTime())->toBe('12:00')
        ->and(SchedulingConstraint::exact('2026-07-22', '15:30')->preferredTime())->toBe('15:30');
});

it('round-trips through toArray/fromArray', function () {
    $c = SchedulingConstraint::window('2026-07-22', 'evening');
    $rebuilt = SchedulingConstraint::fromArray($c->toArray());

    expect($rebuilt->toArray())->toBe($c->toArray());
});

// ---- phrase() bilingual output ----

it('phrases an exact constraint in English and Spanish', function () {
    $c = SchedulingConstraint::exact('2026-07-22', '14:00'); // Wednesday

    expect($c->phrase(Language::English))->toBe('Wednesday, July 22 at 2pm')
        ->and($c->phrase(Language::Spanish))->toBe('miércoles, 22 de julio a las 2pm');
});

it('phrases a window constraint with the range spelled out', function () {
    $c = SchedulingConstraint::window('2026-07-22', 'morning');

    expect($c->phrase(Language::English))->toBe('Wednesday, July 22 in the morning (8am-12pm)')
        ->and($c->phrase(Language::Spanish))->toBe('miércoles, 22 de julio por la mañana (8am-12pm)');
});

// ---- the concrete slot we actually book ----

it('phrases the booked slot as the 2-hour window, not the customers whole window', function () {
    $c = SchedulingConstraint::window('2026-07-22', 'morning');

    expect($c->bookedPhrase('08:00', Language::English))->toBe('Wednesday, July 22 from 8am to 10am')
        ->and($c->bookedPhrase('10:00', Language::Spanish))->toBe('miércoles, 22 de julio de 10am a 12pm');
});

it('phrases an exact-time booking as a single time - it has no arrival window', function () {
    $c = SchedulingConstraint::exact('2026-07-22', '14:00');

    expect($c->bookedPhrase('14:00', Language::English))->toBe('Wednesday, July 22 at 2pm');
});

/**
 * Whether there is a choice left to make once the tech says yes: a 4-hour
 * morning leaves one (which 2 hours?), an exact request or an already-2-hour
 * evening does not.
 */
it('knows when a window is wide enough to be worth narrowing with the tech', function () {
    expect(SchedulingConstraint::window('2026-07-22', 'morning')->needsSlotNarrowing())->toBeTrue()
        ->and(SchedulingConstraint::window('2026-07-22', 'afternoon')->needsSlotNarrowing())->toBeTrue()
        ->and(SchedulingConstraint::flexible('2026-07-22')->needsSlotNarrowing())->toBeTrue()
        ->and(SchedulingConstraint::window('2026-07-22', 'evening')->needsSlotNarrowing())->toBeFalse()
        ->and(SchedulingConstraint::exact('2026-07-22', '14:00')->needsSlotNarrowing())->toBeFalse();
});
