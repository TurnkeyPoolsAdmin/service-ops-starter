<?php

namespace App\Support;

/**
 * Person-name casing. Lead parses often arrive ALL-CAPS ("JOHN SMITH") or
 * all-lowercase; we normalize to "John Smith" everywhere — including the
 * {first_name} merge var in automated texts. Deliberately intentional-case
 * preserving: a word that already mixes cases (McDonald, DeShawn) is left
 * exactly as typed.
 */
class Name
{
    public static function normalizeCase(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return $value;
        }

        // Work per letter-run so hyphens/apostrophes split naturally:
        // "O'BRIEN" → "O'Brien", "SMITH-JONES" → "Smith-Jones".
        return preg_replace_callback('/\p{L}+/u', function (array $m) {
            $word = $m[0];
            $upper = mb_strtoupper($word);
            $lower = mb_strtolower($word);

            // Mixed case is a deliberate choice — never touch it.
            if ($word !== $upper && $word !== $lower) {
                return $word;
            }

            // Single letters read as initials — keep them uppercase.
            if (mb_strlen($word) === 1) {
                return $upper;
            }

            return mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1);
        }, $value);
    }
}
