<?php

namespace App\Services\Vendors\Mygrant;

/**
 * Best-effort vehicle extraction from Mygrant's Part_Description free text.
 * Three shapes seen in the real order-history export:
 *   "19-25 Toyota RAV4 4D U (Slr)"      -> range, closed end
 *   "22- Mercedes C-Class ..."          -> range, OPEN end ("still current")
 *   "2024 Lincoln Aviator 4D U (...)"   -> single model year, no dash at all
 * Consumables (wiper blades, urethane, primer, moulding, filler kits, ...)
 * don't start with any of the three shapes, so they fall through to
 * all-null — that null IS the "no vehicle here" signal the rest of the app
 * reads (e.g. the consumable_max_cents heuristic in config('glass.vendors')).
 *
 * This is intentionally NOT a vehicle database: make is just the first word
 * after the year, and model extraction stops at the first body-style token
 * (door count, "U", Sedan/Coupe/...) or a "(" of feature codes — good enough
 * for display, not guaranteed to match a fitment guide word-for-word.
 */
class PartDescriptionParser
{
    private const STOP_WORDS = [
        'U', 'SEDAN', 'COUPE', 'HATCHBACK', 'WAGON', 'VAN', 'CAB',
        'UPPER', 'LOWER', 'F/L', 'F/R', 'R/L', 'R/R', '2D', '3D', '4D',
    ];

    /**
     * @return array{years_from: ?int, years_to: ?int, make: ?string, model: ?string}
     */
    public static function parseVehicle(?string $description): array
    {
        $empty = ['years_from' => null, 'years_to' => null, 'make' => null, 'model' => null];

        $description = trim((string) $description);
        if ($description === '') {
            return $empty;
        }

        // "19-25 ..." / "22- ..." (open-ended — note the double-space some
        // rows have between the dash and the make; \s+ absorbs any amount).
        if (preg_match('/^(\d{2})-(\d{2})?\s+(.+)$/', $description, $m)) {
            $yearsFrom = self::fourDigitYear((int) $m[1]);
            $yearsTo = $m[2] !== '' ? self::fourDigitYear((int) $m[2]) : null;
            $rest = $m[3];
        } elseif (preg_match('/^(\d{4})\s+(.+)$/', $description, $m)) {
            // "2024 Lincoln Aviator ..." — a single current-model-year part,
            // no dash at all.
            $yearsFrom = (int) $m[1];
            $yearsTo = $yearsFrom;
            $rest = $m[2];
        } else {
            return $empty;
        }

        [$make, $model] = self::splitMakeModel($rest);
        if ($make === null) {
            return $empty;
        }

        return ['years_from' => $yearsFrom, 'years_to' => $yearsTo, 'make' => $make, 'model' => $model ?: null];
    }

    /** Pivot chosen for this dataset's span (1996-2025-ish); no part predates the 90s. */
    private static function fourDigitYear(int $twoDigitYear): int
    {
        return $twoDigitYear <= 30 ? 2000 + $twoDigitYear : 1900 + $twoDigitYear;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function splitMakeModel(string $rest): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($rest)) ?: [], fn ($w) => $w !== ''));
        if (empty($words)) {
            return [null, null];
        }

        $make = array_shift($words);

        $modelWords = [];
        foreach ($words as $word) {
            if (str_starts_with($word, '(') || $word === '|') {
                break;
            }
            if (in_array(strtoupper($word), self::STOP_WORDS, true)) {
                break;
            }
            $modelWords[] = $word;
        }

        return [$make, implode(' ', $modelWords)];
    }
}
