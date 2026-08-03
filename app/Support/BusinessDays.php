<?php

namespace App\Support;

use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Business-day math for the parts-ordering "within next business day" rule.
 * Business days are Mon-Sat (Sunday is never one), minus tagged holidays. All
 * math operates on local (display-tz) calendar dates.
 */
class BusinessDays
{
    /** The next business day strictly after the given local date. */
    public static function nextBusinessDay(CarbonInterface $localDate): CarbonImmutable
    {
        $d = CarbonImmutable::parse($localDate->format('Y-m-d'), Timezone::display())->addDay();

        while ($d->isSunday() || Holiday::isHoliday($d)) {
            $d = $d->addDay();
        }

        return $d;
    }

    /**
     * True when the scheduled local date is today, in the past, or falls on/
     * before the next business day from now. Drives both the Flow A prompt and
     * the "Parts to Order" ready-vs-ahead bucket.
     */
    public static function isWithinNextBusinessDay(CarbonInterface $scheduledLocal, ?CarbonInterface $nowLocal = null): bool
    {
        $now = $nowLocal ?? Timezone::now();
        $cutoff = self::nextBusinessDay($now);

        return $scheduledLocal->format('Y-m-d') <= $cutoff->format('Y-m-d');
    }
}
