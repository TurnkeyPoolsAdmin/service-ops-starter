<?php

namespace App\Services\Vendors\Mygrant;

use Carbon\Carbon;

/**
 * Mygrant's weekly billing math. Everything is computed in
 * config('glass.timezone') and returns a plain 'Y-m-d' string (never a
 * Carbon instance / date cast — see the vendor_documents migration note).
 *
 * cutDate: validated against 228 real invoice groups with ZERO exceptions —
 * see config/glass.php's 'vendors.mygrant.cut_weekday' comment for the rule
 * in prose (next cut day, unless that crosses into next month, in which case
 * the period is shortened to end on the last day of the current month).
 */
class BillingCycle
{
    public static function cutDate(string $confirmedDate): string
    {
        $tz = config('glass.timezone');
        $cutWeekday = (int) config('glass.vendors.mygrant.cut_weekday', 0);

        $date = Carbon::parse($confirmedDate, $tz)->startOfDay();

        // A confirmed date that already falls ON the cut weekday (a Sunday)
        // is its own next cut day — Carbon::next() would otherwise jump a
        // full week ahead.
        $nextCutDay = $date->dayOfWeek === $cutWeekday ? $date->copy() : $date->copy()->next($cutWeekday);

        $endOfMonth = $date->copy()->endOfMonth()->startOfDay();

        return $nextCutDay->lessThanOrEqualTo($endOfMonth)
            ? $nextCutDay->toDateString()
            : $endOfMonth->toDateString();
    }

    public static function settleDate(string $cutDate): string
    {
        $tz = config('glass.timezone');
        $settleWeekday = (int) config('glass.vendors.mygrant.settle_weekday', 3);
        $lagDays = (int) config('glass.vendors.mygrant.settle_lag_days', 3);

        $target = Carbon::parse($cutDate, $tz)->startOfDay()->addDays($lagDays);

        if ($target->dayOfWeek !== $settleWeekday) {
            $target = $target->next($settleWeekday);
        }

        return $target->toDateString();
    }
}
