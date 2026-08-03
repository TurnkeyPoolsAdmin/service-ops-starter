<?php

use App\Support\BusinessHours;
use Carbon\Carbon;

// Feature (not Unit) so the empty holidays table exists for Holiday::isHoliday.

$la = 'America/Los_Angeles';

it('is business hours on a weekday afternoon', function () use ($la) {
    // Wed 2026-07-01, 2pm PT.
    expect(BusinessHours::isAfterHours(Carbon::parse('2026-07-01 14:00', $la)))->toBeFalse();
});

it('is after-hours on a weeknight from 7pm', function () use ($la) {
    expect(BusinessHours::isAfterHours(Carbon::parse('2026-07-01 19:00', $la)))->toBeTrue()
        ->and(BusinessHours::isAfterHours(Carbon::parse('2026-07-01 18:59', $la)))->toBeFalse();
});

it('is after-hours all day Sunday', function () use ($la) {
    // Sun 2026-06-28.
    expect(BusinessHours::isAfterHours(Carbon::parse('2026-06-28 10:00', $la)))->toBeTrue();
});

it('is after-hours on Saturday only from 4pm', function () use ($la) {
    // Sat 2026-06-27.
    expect(BusinessHours::isAfterHours(Carbon::parse('2026-06-27 16:00', $la)))->toBeTrue()
        ->and(BusinessHours::isAfterHours(Carbon::parse('2026-06-27 15:59', $la)))->toBeFalse();
});
