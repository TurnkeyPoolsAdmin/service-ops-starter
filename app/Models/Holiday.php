<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A holiday tagged by an admin, matched against a local (display-tz) calendar
 * date. Drives the holiday / pre-holiday dispatch-intro variants — see
 * App\Enums\TemplateKey::welcomeDispatchIntroFor().
 *
 * Three recurrence types:
 *  - fixed:    one explicit `date` (re-added each year; e.g. a floating date)
 *  - annual:   same `month`/`day` every year (e.g. Jan 1)
 *  - relative: the `week`th `weekday` of `month` every year (e.g. 3rd Thu of Nov)
 */
class Holiday extends Model
{
    public const TYPE_FIXED = 'fixed';

    public const TYPE_ANNUAL = 'annual';

    public const TYPE_RELATIVE = 'relative';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'month' => 'integer',
            'day' => 'integer',
            'weekday' => 'integer',
            'week' => 'integer',
        ];
    }

    /** Is the given local (display-tz) date tagged as a holiday by any rule? */
    public static function isHoliday(CarbonInterface $localDate): bool
    {
        return static::query()->get()->contains(fn (Holiday $h) => $h->matches($localDate));
    }

    /** Does this holiday rule fall on the given local date? */
    public function matches(CarbonInterface $localDate): bool
    {
        return match ($this->type) {
            self::TYPE_ANNUAL => $this->month && $this->day
                && (int) $localDate->month === (int) $this->month
                && (int) $localDate->day === (int) $this->day,
            self::TYPE_RELATIVE => $this->matchesRelative($localDate),
            default => $this->date !== null
                && $this->date->format('Y-m-d') === $localDate->format('Y-m-d'),
        };
    }

    private function matchesRelative(CarbonInterface $localDate): bool
    {
        if (! $this->month || $this->weekday === null || ! $this->week) {
            return false;
        }
        if ((int) $localDate->month !== (int) $this->month) {
            return false;
        }

        $occurrence = self::nthWeekdayOfMonth(
            (int) $localDate->year, (int) $this->month, (int) $this->weekday, (int) $this->week
        );

        return $occurrence !== null && $occurrence->format('Y-m-d') === $localDate->format('Y-m-d');
    }

    /**
     * The date of the Nth (1-5, or -1 = last) given weekday (0=Sun..6=Sat) in a
     * month, or null when that ordinal doesn't exist (e.g. a 5th Friday).
     */
    public static function nthWeekdayOfMonth(int $year, int $month, int $weekday, int $week): ?Carbon
    {
        $tz = config('glass.timezone', 'America/Los_Angeles');
        $first = Carbon::create($year, $month, 1, 0, 0, 0, $tz);

        if ($week === -1) {
            $d = $first->copy()->endOfMonth();
            while ($d->dayOfWeek !== $weekday) {
                $d->subDay();
            }

            return $d;
        }

        $d = $first->copy();
        while ($d->dayOfWeek !== $weekday) {
            $d->addDay();
        }
        $d->addWeeks($week - 1);

        return (int) $d->month === $month ? $d : null;
    }

    /** Human-readable summary of the rule, for the admin list. */
    public function describe(): string
    {
        $months = [1 => 'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'];
        $weekdays = [0 => 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $ordinals = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th', -1 => 'Last'];

        return match ($this->type) {
            self::TYPE_ANNUAL => 'Every '.($months[$this->month] ?? '?').' '.$this->day,
            self::TYPE_RELATIVE => ($ordinals[$this->week] ?? '?').' '
                .($weekdays[$this->weekday] ?? '?').' of '.($months[$this->month] ?? '?'),
            default => $this->date?->format('D, M j, Y') ?? '—',
        };
    }
}
