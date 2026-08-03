<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Central helper for the store-UTC / display-Pacific timezone discipline.
 *
 * Storage timezone is always UTC (config('app.timezone')). The operator-facing
 * display timezone and all "send at" scheduling math use config('glass.timezone'),
 * which defaults to America/Los_Angeles.
 */
class Timezone
{
    public static function display(): string
    {
        return config('glass.timezone', 'America/Los_Angeles');
    }

    /** Current time as a Carbon in the display timezone. */
    public static function now(): Carbon
    {
        return Carbon::now(self::display());
    }

    /** Interpret a stored (UTC) datetime in the display timezone for presentation. */
    public static function toDisplay(?CarbonInterface $utc): ?Carbon
    {
        return $utc?->copy()->setTimezone(self::display());
    }

    /**
     * Given a local calendar date (any Carbon/parseable date), return the
     * [startUtc, endUtc] bounds of that whole day in the display timezone,
     * expressed in UTC for querying stored timestamps.
     */
    public static function localDayUtcRange(CarbonInterface|string $localDate): array
    {
        $date = $localDate instanceof CarbonInterface
            ? $localDate->copy()->setTimezone(self::display())
            : Carbon::parse($localDate, self::display());

        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        return [$start->utc(), $end->utc()];
    }

    /**
     * Build a UTC timestamp from a local (display-tz) date + "HH:MM" wall-clock time.
     * Used for reminder/review send-time computation.
     */
    public static function localDateTimeToUtc(CarbonInterface $localDate, string $hhmm): Carbon
    {
        [$h, $m] = array_pad(explode(':', $hhmm), 2, '0');

        return $localDate->copy()
            ->setTimezone(self::display())
            ->setTime((int) $h, (int) $m, 0)
            ->utc();
    }
}
