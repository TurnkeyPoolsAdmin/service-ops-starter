<?php

namespace App\Support;

/**
 * Lightweight US-centric phone normalizer. The business operates in the US,
 * so we normalize to +1XXXXXXXXXX E.164. Returns null when the input cannot
 * be confidently normalized (parser records the raw value as a warning).
 */
class PhoneNumber
{
    public static function toE164(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $hasPlus = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $trimmed);

        if ($digits === '') {
            return null;
        }

        // Already +country code (e.g. pasted as +1..., +44...). Trust it if plausible.
        if ($hasPlus) {
            return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+'.$digits : null;
        }

        // US 10-digit -> +1XXXXXXXXXX
        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        // US 11-digit leading 1 -> +1XXXXXXXXXX
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return null;
    }

    /**
     * Human-readable form for a number we put IN a message body — a tech's job
     * card, say. US numbers read "(323) 555-0134"; anything else falls through
     * unchanged, so a foreign or unparseable number is never mangled. Phones
     * still detect either shape as tap-to-call. Null in, null out.
     */
    public static function toDisplay(?string $raw): ?string
    {
        $e164 = self::toE164($raw);
        if ($e164 === null) {
            return $raw === null ? null : (trim($raw) ?: null);
        }

        if (! str_starts_with($e164, '+1') || strlen($e164) !== 12) {
            return $e164;
        }

        $digits = substr($e164, 2);

        return '('.substr($digits, 0, 3).') '.substr($digits, 3, 3).'-'.substr($digits, 6);
    }
}
