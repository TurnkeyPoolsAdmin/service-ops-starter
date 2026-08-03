<?php

namespace App\Support;

use App\Models\Holiday;
use Carbon\CarbonImmutable;

/**
 * The days we are closed — Sundays and tagged holidays — as a flat list of
 * dates a Filament DatePicker can refuse. Used so a requested day can never be
 * booked on a day nobody is working (the scheduling ask, the parts-order
 * windows and the reminder cadence all assume an open day).
 *
 * Sundays are emitted alongside the holidays rather than left to the picker's
 * own weekday rule, so one ->disabledDates() call covers both.
 */
class ClosedDays
{
    /**
     * Every closed day from today through $days ahead (a year by default —
     * comfortably past anything a customer would book).
     *
     * @return array<int, string> Y-m-d
     */
    public static function upcoming(int $days = 365): array
    {
        // Load the holiday rules ONCE — Holiday::isHoliday() queries on every
        // call, which over a year of dates would be a query per day.
        $holidays = Holiday::all();

        $closed = [];
        $cursor = CarbonImmutable::today(Timezone::display());

        for ($i = 0; $i <= $days; $i++) {
            if ($cursor->isSunday() || $holidays->contains(fn (Holiday $h) => $h->matches($cursor))) {
                $closed[] = $cursor->toDateString();
            }
            $cursor = $cursor->addDay();
        }

        return $closed;
    }

    /** True when we are shut that local date (Sunday or a tagged holiday). */
    public static function isClosed(CarbonImmutable|string $date): bool
    {
        $day = $date instanceof CarbonImmutable
            ? $date
            : CarbonImmutable::parse($date, Timezone::display());

        return $day->isSunday() || Holiday::isHoliday($day);
    }

    /**
     * The nearest open day on or after $date. Used to pull a requested day off
     * a Sunday/holiday — the picker refuses to select one, so a parsed date
     * that landed there has to be moved before it reaches the form.
     */
    public static function nextOpen(string $date): string
    {
        $day = CarbonImmutable::parse($date, Timezone::display());

        return self::isClosed($day)
            ? BusinessDays::nextBusinessDay($day->subDay())->toDateString()
            : $date;
    }
}
