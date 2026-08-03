<?php

namespace App\Support;

use App\Enums\Language;
use Carbon\Carbon;

/**
 * Reading and spelling out `jobs.requested_day` — the day the customer asked
 * for. The column stores a Y-m-d string (written by the intake DatePicker), but
 * jobs taken before it was a calendar still hold free text ("Tomorrow", "next
 * Fri"), so every read has to tolerate both.
 *
 * Shared by the job form, the SMS merge vars, and the scheduling-ask copy so
 * they can't drift on how a requested day reads.
 */
class RequestedDay
{
    /** Y-m-d if the stored value is a real date, else null (free text / blank). */
    public static function asDate(mixed $state): ?string
    {
        if ($state instanceof \DateTimeInterface) {
            return $state->format('Y-m-d');
        }

        $value = trim((string) $state);
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The requested day spelled out for humans/SMS — "Monday, July 21" /
     * "lunes, 21 de julio". Legacy free-text values pass through unchanged
     * (untranslatable, but better than dropping them); blank in, null out.
     */
    public static function spellOut(mixed $day, ?Language $language = null): ?string
    {
        $raw = trim((string) ($day instanceof \DateTimeInterface ? $day->format('Y-m-d') : $day));
        if ($raw === '') {
            return null;
        }

        $date = self::asDate($raw);
        if ($date === null) {
            return $raw;
        }

        return self::spell(Carbon::parse($date, Timezone::display()), $language);
    }

    /** "Monday, July 21" / "lunes, 21 de julio". */
    public static function spell(Carbon $date, ?Language $language = null): string
    {
        return $language === Language::Spanish
            ? $date->locale('es')->translatedFormat('l, j \d\e F')
            : $date->format('l, F j');
    }
}
