<?php

namespace App\Support;

/**
 * The canonical glass-position vocabulary.
 *
 * `jobs.glass_position` is a plain string and reps write it a dozen ways ("WS",
 * "W/S", "windshield", "Windscreen"). WhatsAppLeadParser::normalizeGlassPosition()
 * already funnels the pasted text into this vocabulary; the forms now offer it as
 * a select so a hand-typed job lands on the same values.
 *
 * Legacy / unparseable rows keep whatever string they had — optionsFor() folds the
 * current value back in so editing a job can never silently blank it.
 */
class GlassPosition
{
    /** @var array<int, string> */
    public const OPTIONS = [
        'Windshield',
        'Back Glass',
        'Front Left Door',
        'Front Right Door',
        'Rear Left Door',
        'Rear Right Door',
        'Front Left Vent Glass',
        'Front Right Vent Glass',
        'Rear Left Quarter Glass',
        'Rear Right Quarter Glass',
        'Sunroof',
    ];

    /**
     * Select options, with $current appended when it isn't part of the vocabulary
     * (legacy free text, or something the parser passed through verbatim).
     *
     * @return array<string, string>
     */
    public static function optionsFor(?string $current = null): array
    {
        $options = array_combine(self::OPTIONS, self::OPTIONS);

        $current = trim((string) $current);
        if ($current !== '' && ! isset($options[$current])) {
            $options[$current] = $current;
        }

        return $options;
    }

    /**
     * Is this position a windshield? Tolerates the raw shorthand ("WS", "W/S")
     * and legacy free text, since only normalized rows say "Windshield" exactly.
     * Calibration only ever applies to a windshield.
     */
    public static function isWindshield(?string $value): bool
    {
        $v = strtolower(trim((string) $value));

        if ($v === '') {
            return false;
        }

        return str_contains($v, 'windshield')
            || str_contains($v, 'windscreen')
            || str_contains($v, 'w/s')
            || (bool) preg_match('/\bws\b/', $v);
    }
}
