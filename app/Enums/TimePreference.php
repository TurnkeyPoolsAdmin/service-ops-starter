<?php

namespace App\Enums;

/**
 * The customer's time-of-day preference for their requested date, stored on
 * `jobs.requested_time_preference`. Structured (unlike the free-text
 * `jobs.requested_time`, which it sits next to) so the post-welcome scheduling
 * ask can branch on it — see App\Support\SchedulingAsk.
 *
 * The free text stays the source for the SPECIFIC time ("2pm"); this column
 * only says WHICH KIND of preference we were given. Legacy jobs have a null
 * column, which reads as None (we never asked) — normalize() maps their old
 * free text onto a case wherever it can.
 *
 * After is the one case that carries STRUCTURED time of its own:
 * `jobs.requested_after_time` holds a 'H:i' wall-clock hour ("10:00") and the
 * case means "any time from that hour to close". It is deliberately not free
 * text — the AI scheduler turns it straight into a window's earliest bound,
 * and a value it cannot parse would silently read as "no preference".
 */
enum TimePreference: string
{
    /** No preference given — we get to ask. */
    case None = 'none';
    /** "ASAP" / "earliest possible" — a KNOWN preference meaning first slot. */
    case Asap = 'asap';
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';
    /** "After 10am" — the hour lives in `requested_after_time`, not the free text. */
    case After = 'after';
    /** A specific clock time, held in the free-text `requested_time`. */
    case Specific = 'specific';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No preference',
            self::Asap => 'ASAP / earliest available',
            self::Morning => 'Morning',
            self::Afternoon => 'Afternoon',
            self::Evening => 'Evening',
            self::After => 'After a time',
            self::Specific => 'Specific time',
        };
    }

    /**
     * The preference as it reads inside the requested-day string that feeds
     * {preferred_date_time} ("Monday, July 21 ASAP"). Shorter than label(),
     * which is written for a form Select; '' for no preference and for
     * Specific, whose wording is the free-text clock time itself.
     */
    public function shortLabel(?string $afterTime = null): string
    {
        return match ($this) {
            self::None, self::Specific => '',
            self::Asap => 'ASAP',
            self::Morning => 'Morning',
            self::Afternoon => 'Afternoon',
            self::Evening => 'Evening',
            // "After 10am", or a bare "After" when the hour is missing.
            self::After => trim('After '.self::formatAfterTime($afterTime)),
        };
    }

    /**
     * True when the customer actually told us something. Drives the whole
     * ask-vs-confirm split of the scheduling text: a known preference is
     * acknowledged, an unknown one is asked about. ASAP counts as KNOWN — it
     * means "your earliest", which is an answer, not a question.
     */
    public function isKnown(): bool
    {
        return $this !== self::None;
    }

    /** Options map for a Filament Select (value => label). */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * The preference as a sentence fragment that follows a date, carrying its
     * own preposition so the copy reads naturally in either language:
     * "Friday, July 24 in the morning" / "... at 2pm" / "... as early as we can".
     * Specific pulls the actual clock time from the job's free-text column.
     * None renders '' (the ask templates never reference it).
     */
    public function phrase(?string $requestedTime = null, ?Language $language = null, ?string $afterTime = null): string
    {
        $spanish = $language === Language::Spanish;
        $time = trim((string) $requestedTime);
        $after = self::formatAfterTime($afterTime);
        // Fall back to the ASAP wording rather than an empty tail when a
        // preference that needs a time somehow lost it.
        $asap = $spanish ? 'lo más temprano posible' : 'as early as we can';

        return match ($this) {
            self::None => '',
            self::Asap => $asap,
            self::Morning => $spanish ? 'por la mañana' : 'in the morning',
            self::Afternoon => $spanish ? 'por la tarde' : 'in the afternoon',
            self::Evening => $spanish ? 'por la noche' : 'in the evening',
            self::After => $after === ''
                ? $asap
                : ($spanish ? "después de las {$after}" : "after {$after}"),
            self::Specific => $time === ''
                ? $asap
                : ($spanish ? "a las {$time}" : "at {$time}"),
        };
    }

    /**
     * The clock times the After preference can be set to — every half hour
     * across the working day, 'H:i' => "10am". A fixed grid rather than a free
     * time picker so both apps, the SMS copy and the AI window all speak the
     * same handful of hours, and so a stored value always renders cleanly.
     *
     * @return array<string, string>
     */
    public static function afterTimeOptions(): array
    {
        $options = [];

        for ($minutes = 7 * 60; $minutes <= 18 * 60; $minutes += 30) {
            $hhmm = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
            $options[$hhmm] = self::formatAfterTime($hhmm);
        }

        return $options;
    }

    /** 'H:i' as a customer reads it — "10am" / "10:30am"; '' for null or junk. */
    public static function formatAfterTime(?string $hhmm): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) $hhmm), $m) !== 1) {
            return '';
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];
        if ($hour > 23 || $minute > 59) {
            return '';
        }

        $suffix = $hour >= 12 ? 'pm' : 'am';
        $hour12 = $hour % 12 === 0 ? 12 : $hour % 12;

        return $minute === 0 ? "{$hour12}{$suffix}" : sprintf('%d:%02d%s', $hour12, $minute, $suffix);
    }

    /**
     * Best-effort read of an After hour out of free text ("after 10am",
     * "anytime after 3") onto the option grid above, so a pasted lead or a
     * legacy job still resolves to a real hour. Null when there is no clock
     * time in the text at all.
     *
     * Snapped DOWN to the half hour and clamped into the working day: "after
     * 10:15" is honoured by 10:00 (we may arrive later, never earlier than
     * asked minus a few minutes), and an hour before we open reads as our
     * opening time.
     */
    public static function afterTimeFrom(?string $text): ?string
    {
        $value = strtolower(trim((string) $text));
        if ($value === '' || preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/', $value, $m) !== 1) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $meridiem = $m[3] ?? '';

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        if ($meridiem === 'pm' && $hour < 12) {
            $hour += 12;
        } elseif ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        } elseif ($meridiem === '' && $hour >= 1 && $hour <= 6) {
            // Shop hours: a bare 1-6 means the afternoon ("after 3").
            $hour += 12;
        }

        $wanted = sprintf('%02d:%02d', $hour, $minute >= 30 ? 30 : 0);
        $grid = array_keys(self::afterTimeOptions());

        $snapped = $grid[0];
        foreach ($grid as $option) {
            if ($option <= $wanted) {
                $snapped = $option;
            }
        }

        return $snapped;
    }

    /**
     * Best-effort read of a legacy / pasted free-text requested time onto a
     * case. Returns null when the text says nothing about time at all (so the
     * caller can leave the column untouched rather than guessing None).
     */
    public static function normalize(?string $value): ?self
    {
        $v = strtolower(trim((string) $value));
        if ($v === '') {
            return null;
        }

        // Checked BEFORE the ASAP words on purpose: "anytime after 3pm" is an
        // AFTER request, and "anytime" on its own would otherwise claim it.
        if ((preg_match('/\bafter\b/', $v) === 1 || str_contains($v, 'despues') || str_contains($v, 'después'))
            && preg_match('/\d/', $v) === 1) {
            return self::After;
        }
        if (preg_match('/\b(?:a\.?s\.?a\.?p\.?|as soon as possible|earliest|first thing|anytime|any time|whenever|flexible)\b/', $v) === 1) {
            return self::Asap;
        }
        if (preg_match('/\b(?:morning|am|a\.m\.|manana)\b/', $v) === 1 && preg_match('/\d/', $v) !== 1) {
            return self::Morning;
        }
        if (preg_match('/\b(?:afternoon|midday|noon|tarde)\b/', $v) === 1 && preg_match('/\d/', $v) !== 1) {
            return self::Afternoon;
        }
        if (preg_match('/\b(?:evening|night|noche)\b/', $v) === 1 && preg_match('/\d/', $v) !== 1) {
            return self::Evening;
        }
        // Anything with a clock time in it is a specific request ("2pm", "10:30").
        if (preg_match('/\d/', $v) === 1) {
            return self::Specific;
        }

        return null;
    }
}
