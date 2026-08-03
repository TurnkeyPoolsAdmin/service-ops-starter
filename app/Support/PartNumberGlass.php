<?php

namespace App\Support;

/**
 * Reads the glass type out of a NAGS part number and compares it to the job's
 * `glass_position`, so a lead that says "door glass" but carries a windshield
 * part number is caught at intake instead of on the tech's van.
 *
 * A NAGS number starts with two letters and then digits — e.g. "FW02435 GBN FYG"
 * or "DW2853GTY":
 *
 *   1st letter  D / F  = Domestic / Foreign  (we ignore it)
 *   2nd letter  W = Windshield · D = Door Glass · V = Vent Glass
 *               Q = Quarter Glass · B = Back Glass · R = Roof (Sunroof)
 *
 * Everything after that (GBN / FYG - color, shade, attachments) is irrelevant here.
 *
 * ⚠ Both sides are ADVISORY. codeForPartNumber() returns null for anything that
 * isn't shaped like a NAGS number (a free-text description, a dealer SKU), and
 * codeForPosition() returns null for a legacy/unrecognized position string. A
 * null on either side means "no opinion" — never a mismatch. We only ever claim
 * a mismatch when BOTH sides read cleanly and disagree.
 */
class PartNumberGlass
{
    /** The 2nd-letter code => what that glass is called. */
    public const CODES = [
        'W' => 'Windshield',
        'D' => 'Door glass',
        'V' => 'Vent glass',
        'Q' => 'Quarter glass',
        'B' => 'Back glass',
        'R' => 'Roof (sunroof)',
    ];

    /**
     * The glass code a part number claims, or null when the label isn't shaped
     * like a NAGS number at all (so we have no opinion about it).
     */
    public static function codeForPartNumber(?string $partNumber): ?string
    {
        $value = ltrim((string) $partNumber);

        if ($value === '') {
            return null;
        }

        // [Domestic/Foreign][glass type][digits] — the digits are what keep a
        // free-text description ("Front door glass") from parsing as a part.
        // No trailing boundary: the color/shade codes often run straight on
        // ("DW2853GTY"), and a digit-to-letter join is not a word boundary.
        if (! preg_match('/^[DF]([WDVQBR])\s*\d{2,6}/i', $value, $m)) {
            return null;
        }

        return strtoupper($m[1]);
    }

    /**
     * The glass code a position names — keyword-based, so it reads the canonical
     * GlassPosition::OPTIONS values AND the shorthand/legacy free text reps type
     * ("WS", "W/S", "rear glass"). Null when nothing recognizable is in there.
     *
     * All four door positions (front/rear, left/right) are one code: D. Same for
     * the two vent and the two quarter positions — the part number only ever
     * says WHICH GLASS, never which side of the car.
     */
    public static function codeForPosition(?string $position): ?string
    {
        $value = strtolower(trim((string) $position));

        if ($value === '') {
            return null;
        }

        if (GlassPosition::isWindshield($value)) {
            return 'W';
        }

        // Checked before 'door'/'glass' so "Front Left Vent Glass" and
        // "Rear Right Quarter Glass" can't be read as anything else.
        if (str_contains($value, 'vent')) {
            return 'V';
        }

        if (str_contains($value, 'quarter')) {
            return 'Q';
        }

        if (str_contains($value, 'sunroof') || str_contains($value, 'moonroof') || str_contains($value, 'roof')) {
            return 'R';
        }

        if (str_contains($value, 'back glass') || str_contains($value, 'backglass')
            || str_contains($value, 'rear glass') || str_contains($value, 'back window')) {
            return 'B';
        }

        if (str_contains($value, 'door')) {
            return 'D';
        }

        return null;
    }

    public static function labelForCode(?string $code): ?string
    {
        return self::CODES[strtoupper((string) $code)] ?? null;
    }

    /**
     * Do this part number and this position disagree? False whenever either side
     * is unreadable — an unknown part number is a question for the sales team,
     * not an assertion that something is wrong.
     */
    public static function disagree(?string $partNumber, ?string $position): bool
    {
        $part = self::codeForPartNumber($partNumber);
        $slot = self::codeForPosition($position);

        return $part !== null && $slot !== null && $part !== $slot;
    }

    /**
     * The mismatch across a whole set of part labels, or null when there isn't
     * one. A job can carry several parts, so it only counts as a mismatch when
     * NO readable part matches the position — one matching part is enough to say
     * the position is accounted for.
     *
     * @param  array<int, ?string>  $partNumbers
     * @return array{part_code: string, part_label: string, part_number: string, position: string, position_code: string}|null
     */
    public static function mismatch(array $partNumbers, ?string $position): ?array
    {
        $slot = self::codeForPosition($position);

        if ($slot === null) {
            return null;
        }

        $offenders = [];
        foreach ($partNumbers as $partNumber) {
            $code = self::codeForPartNumber($partNumber);

            if ($code === null) {
                continue;
            }

            if ($code === $slot) {
                return null;
            }

            $offenders[] = [$code, trim((string) $partNumber)];
        }

        if ($offenders === []) {
            return null;
        }

        [$code, $number] = $offenders[0];

        return [
            'part_code' => $code,
            'part_label' => (string) self::labelForCode($code),
            'part_number' => $number,
            'position' => trim((string) $position),
            'position_code' => $slot,
        ];
    }

    /**
     * A stable fingerprint of one mismatch ("W|Front Left Door"), stored when
     * dispatch overrides it at scheduling. The override only excuses the exact
     * pairing that was overridden — edit the part number or the position into a
     * DIFFERENT mismatch and it has to be looked at again.
     */
    public static function signature(array $mismatch): string
    {
        return $mismatch['part_code'].'|'.$mismatch['position'];
    }

    /** "Part number FW02435 reads Windshield, but the glass position is Front Left Door." */
    public static function sentence(array $mismatch): string
    {
        return 'Part number '.$mismatch['part_number'].' reads '.strtolower($mismatch['part_label'])
            .', but the glass position is '.$mismatch['position'].'.';
    }
}
