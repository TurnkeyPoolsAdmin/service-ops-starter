<?php

namespace App\Support;

/**
 * Normalizes a vendor part-number string down to its stable "base" code, so
 * the same part matches whether it's written space-delimited (Mygrant's CSV
 * shape, "FW05851 GTY FYG" — part number + finish/feature suffix codes) or
 * glued together the way a dispatcher sometimes types it on a job
 * ("FB30470YPY", no space at all).
 *
 * We can't rely on splitting at the first space (there may not be one), so
 * instead: strip everything but letters/digits, then take the leading
 * LETTERS-then-DIGITS run (the part code itself, e.g. "FW" + "05851") and
 * drop whatever trailing letters follow (the suffix codes — GTY, FYG, YPN,
 * ...). This is a heuristic, not a parts catalog: codes that don't follow
 * the letters-then-digits shape (rare consumable codes like "WKT F2000
 * GOLD") still normalize deterministically, just not necessarily to the
 * exact token a human would call "the" part number.
 */
class PartNumber
{
    public static function normalizeBase(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $raw));
        if ($clean === '') {
            return null;
        }

        if (preg_match('/^([A-Z]{1,4}\d{3,7})/', $clean, $m)) {
            return $m[1];
        }

        return $clean;
    }
}
