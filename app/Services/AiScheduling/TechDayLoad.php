<?php

namespace App\Services\AiScheduling;

use App\Enums\Language;

/**
 * How busy a technician's existing schedule looks on one local day, bucketed
 * by part of day. Per the owner: a tech with one 8-10am job can usually still
 * take another morning job (the two-hour arrival window doesn't mean the
 * visit fills it); two starts to be genuinely busy. See TechAvailability.
 */
final class TechDayLoad
{
    public const OPEN = 'open';

    public const LIGHT = 'light';

    public const BUSY = 'busy';

    public function __construct(
        public readonly int $morning = 0,
        public readonly int $afternoon = 0,
        public readonly int $evening = 0,
    ) {}

    public function total(): int
    {
        return $this->morning + $this->afternoon + $this->evening;
    }

    public function inPart(string $part): int
    {
        return match ($part) {
            'morning' => $this->morning,
            'afternoon' => $this->afternoon,
            'evening' => $this->evening,
            default => $this->total(),
        };
    }

    /** open (0 jobs) | light (1 job) | busy (>= busy_threshold) for the constraint's part of day. */
    public function bucket(SchedulingConstraint $constraint): string
    {
        $count = $this->inPart($constraint->partOfDay);
        if ($count <= 0) {
            return self::OPEN;
        }

        $busyAt = (int) config('glass.ai_scheduling.busy_threshold', 2);

        return $count >= $busyAt ? self::BUSY : self::LIGHT;
    }

    /**
     * "You already have 1 job that morning. " merge-var text (note the
     * trailing space, so a template can write "{load_note}Can you take it?"
     * and get either "You already have... Can you take it?" or, when open,
     * plain "Can you take it?" with no double space); '' when open.
     */
    public function noteFor(SchedulingConstraint $constraint, ?Language $language): string
    {
        $count = $this->inPart($constraint->partOfDay);
        if ($count <= 0) {
            return '';
        }

        $spanish = $language === Language::Spanish;
        $part = self::partWord($constraint->partOfDay, $spanish);

        if ($count === 1) {
            return $spanish
                ? "Ya tienes un trabajo {$part}. "
                : "You already have one job {$part}. ";
        }

        return $spanish
            ? "Ya tienes {$count} trabajos {$part}. "
            : "You already have {$count} jobs {$part}. ";
    }

    private static function partWord(string $part, bool $spanish): string
    {
        return match ($part) {
            'morning' => $spanish ? 'esa mañana' : 'that morning',
            'afternoon' => $spanish ? 'esa tarde' : 'that afternoon',
            'evening' => $spanish ? 'esa noche' : 'that evening',
            default => $spanish ? 'ese día' : 'that day',
        };
    }
}
