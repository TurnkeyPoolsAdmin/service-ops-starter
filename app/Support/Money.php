<?php

namespace App\Support;

class Money
{
    /**
     * Parse a human money string ("950", "$720.00", "1,200") into integer cents.
     * Returns null when no numeric value can be found.
     */
    public static function parseToCents(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        // Keep digits and decimal point only.
        $cleaned = preg_replace('/[^0-9.]/', '', $raw);
        if ($cleaned === '' || ! preg_match('/\d/', $cleaned)) {
            return null;
        }

        // Collapse accidental multiple dots — keep the first as the decimal sep.
        $parts = explode('.', $cleaned);
        if (count($parts) > 2) {
            $cleaned = $parts[0].'.'.implode('', array_slice($parts, 1));
        }

        return (int) round(((float) $cleaned) * 100);
    }

    /** Format integer cents as "$1,234.56" for display. */
    public static function format(?int $cents): string
    {
        if ($cents === null) {
            return '—';
        }

        return '$'.number_format($cents / 100, 2);
    }

    /**
     * Parse an UNAMBIGUOUS decimal string ("403.06", "-607.06", "0.00000")
     * into signed integer cents WITHOUT ever going through a float. Used for
     * vendor feeds (Mygrant order-history CSV / credit-memo PDF) where the
     * source amount is always exact to the cent — any decimal places beyond
     * two are zero-padding — so a straight string split is both safe and
     * sidesteps float-rounding entirely. Unlike parseToCents() above, this
     * does NOT try to recover a number from messy human text; blank input
     * returns 0 cents (a CSV cell is either a real amount or empty).
     */
    public static function centsFromDecimalString(?string $raw): int
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return 0;
        }

        $negative = str_starts_with($raw, '-');
        $raw = str_replace(',', '', ltrim($raw, '+-'));

        [$whole, $frac] = array_pad(explode('.', $raw, 2), 2, '0');
        $whole = $whole === '' ? '0' : $whole;
        $frac = str_pad(substr($frac, 0, 2), 2, '0');

        $cents = ((int) $whole) * 100 + (int) $frac;

        return $negative ? -$cents : $cents;
    }
}
