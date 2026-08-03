<?php

namespace App\Services\AiScheduling;

use App\Enums\Language;
use App\Support\RequestedDay;
use App\Support\Timezone;
use Carbon\Carbon;

/**
 * The customer's scheduling requirement for an AI-negotiated job: a fixed
 * date plus how strict the time has to be. This — not a single instant — is
 * the unit the tech negotiation runs against, so "the customer can only do
 * mornings" is something a counter-offer can be checked against in code
 * rather than left to the LLM to judge. See docs/ai-scheduling-spec.md §1.1.
 *
 * The date never moves during tech negotiation (a tech who can't do that day
 * declines and the session moves to the next tech) — only a customer reply
 * proposing a different day can retarget a session onto a new constraint.
 */
final class SchedulingConstraint
{
    public const FIRM_EXACT = 'exact';

    public const FIRM_WINDOW = 'window';

    /** "After 10am" — a lower bound only; anything from there to close works. */
    public const FIRM_AFTER = 'after';

    public const FIRM_FLEXIBLE = 'flexible';

    public function __construct(
        public readonly string $date,          // Y-m-d, Pacific
        public readonly ?string $exactTime,     // 'H:i' when firmness = exact
        public readonly string $partOfDay,      // morning | afternoon | evening | any
        public readonly string $firmness,       // exact | window | after | flexible
        public readonly string $earliest,       // 'H:i'
        public readonly string $latest,         // 'H:i'
    ) {}

    public static function exact(string $date, string $time): self
    {
        return new self($date, $time, self::partOfDayForTime($time), self::FIRM_EXACT, $time, $time);
    }

    public static function window(string $date, string $partOfDay): self
    {
        [$start, $end] = self::dayPartBounds($partOfDay);

        return new self($date, null, $partOfDay, self::FIRM_WINDOW, $start, $end);
    }

    /**
     * "Any time after 10am" — the customer's window runs from the hour they
     * named to close. partOfDay stays 'any' because the window straddles them:
     * a tech's load note then reads against their whole day, which is the
     * honest answer for a request that spans most of it.
     */
    public static function after(string $date, string $time): self
    {
        [$open, $close] = self::businessDaySpan();
        // An hour outside business hours still has to leave a usable window;
        // clamp rather than produce a latest that sits before the earliest.
        $earliest = max($open, min($time, $close));

        return new self($date, null, 'any', self::FIRM_AFTER, $earliest, $close);
    }

    public static function flexible(string $date): self
    {
        [$open, $close] = self::businessDaySpan();

        return new self($date, null, 'any', self::FIRM_FLEXIBLE, $open, $close);
    }

    /**
     * Does a proposed date+time satisfy this constraint? The date must match
     * exactly; the time is checked per firmness. A null time (the other party
     * simply agreed, without naming a clock time) always satisfies — the
     * constraint's own preferredTime() supplies the actual slot to book.
     */
    public function admits(string $date, ?string $time): bool
    {
        if ($date !== $this->date) {
            return false;
        }

        if ($time === null) {
            return true;
        }

        return match ($this->firmness) {
            self::FIRM_EXACT => self::minutesBetween($time, (string) $this->exactTime)
                <= (int) config('glass.ai_scheduling.exact_tolerance_minutes', 0),
            default => $time >= $this->earliest && $time <= $this->latest,
        };
    }

    /**
     * The concrete time to book when nobody named a more specific one — the
     * START of the customer's window, deliberately: given a whole morning to
     * choose from we always go early first (owner's call, 2026-07-26).
     */
    public function preferredTime(): string
    {
        return $this->exactTime ?? $this->earliest;
    }

    /**
     * Is the customer's window wider than the arrival window we actually book?
     * "Tuesday morning" is 8am-12pm but the job goes on the calendar as a
     * 2-hour slot, so there is a real choice left to make (which is what the
     * slot-confirmation step asks the tech about). "Tuesday at 9" or a
     * day-part that is already 2 hours long leaves nothing to narrow.
     */
    public function needsSlotNarrowing(): bool
    {
        if ($this->firmness === self::FIRM_EXACT) {
            return false;
        }

        return self::minutesBetween($this->latest, $this->earliest) > self::bookingWindowHours() * 60;
    }

    /** The arrival window a booked job actually gets — the same one JobLifecycle::schedule falls back to. */
    public static function bookingWindowHours(): int
    {
        return max(1, (int) config('glass.appointment_window_hours', 2));
    }

    /**
     * The CONCRETE slot we are booking, in the recipient's language:
     * "Monday, July 27 from 8am to 10am" — as opposed to phrase(), which
     * describes the customer's whole window ("Monday in the morning
     * (8am-12pm)"). An exact-time request has no window, so it reads as
     * "Monday at 2pm".
     */
    public function bookedPhrase(string $time, ?Language $language): string
    {
        $day = RequestedDay::spell(Carbon::parse($this->date, Timezone::display()), $language);
        $spanish = $language === Language::Spanish;

        if ($this->firmness === self::FIRM_EXACT) {
            return $day.' '.($spanish ? 'a las ' : 'at ').self::formatTime($time);
        }

        $end = self::addHours($time, self::bookingWindowHours());

        return $day.' '.($spanish ? 'de ' : 'from ').self::formatTime($time)
            .($spanish ? ' a ' : ' to ').self::formatTime($end);
    }

    /** "Tuesday at 10am" / "Tuesday in the morning (8am-12pm)" / "Tuesday", in the recipient's language. */
    public function phrase(?Language $language): string
    {
        $day = RequestedDay::spell(Carbon::parse($this->date, Timezone::display()), $language);
        $spanish = $language === Language::Spanish;

        return match ($this->firmness) {
            self::FIRM_EXACT => $day.' '.($spanish ? 'a las ' : 'at ').self::formatTime((string) $this->exactTime),
            self::FIRM_WINDOW => $day.' '.self::partOfDayPhrase($this->partOfDay, $spanish)
                .' ('.self::formatTime($this->earliest).'-'.self::formatTime($this->latest).')',
            self::FIRM_AFTER => $day.' '.($spanish ? 'después de las ' : 'after ').self::formatTime($this->earliest),
            default => $day,
        };
    }

    /** Short form for a tech's "you already have X" load note — no window spelled out, no day. */
    public function shortPhrase(?Language $language): string
    {
        $spanish = $language === Language::Spanish;

        return match ($this->firmness) {
            self::FIRM_EXACT => self::formatTime((string) $this->exactTime),
            self::FIRM_WINDOW => self::partOfDayPhrase($this->partOfDay, $spanish),
            self::FIRM_AFTER => ($spanish ? 'después de las ' : 'after ').self::formatTime($this->earliest),
            default => $spanish ? 'cualquier hora' : 'any time',
        };
    }

    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'exact_time' => $this->exactTime,
            'part_of_day' => $this->partOfDay,
            'firmness' => $this->firmness,
            'earliest' => $this->earliest,
            'latest' => $this->latest,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string) $a['date'],
            $a['exact_time'] ?? null,
            (string) ($a['part_of_day'] ?? 'any'),
            (string) ($a['firmness'] ?? self::FIRM_FLEXIBLE),
            (string) ($a['earliest'] ?? '07:00'),
            (string) ($a['latest'] ?? '19:00'),
        );
    }

    /** Which configured day-part a 'H:i' clock time falls in, or 'any' outside all of them. */
    public static function partOfDayForTime(string $time): string
    {
        foreach (['morning', 'afternoon', 'evening'] as $part) {
            [$start, $end] = self::dayPartBounds($part);
            if ($time >= $start && $time < $end) {
                return $part;
            }
        }

        return 'any';
    }

    /** @return array{0: string, 1: string} */
    private static function dayPartBounds(string $part): array
    {
        $bounds = config("glass.ai_scheduling.day_parts.{$part}");

        return is_array($bounds) && count($bounds) === 2 ? $bounds : ['07:00', '19:00'];
    }

    /** @return array{0: string, 1: string} the earliest part's start to the latest part's end. */
    private static function businessDaySpan(): array
    {
        $parts = config('glass.ai_scheduling.day_parts', []);
        if ($parts === []) {
            return ['07:00', '19:00'];
        }

        $starts = array_column($parts, 0);
        $ends = array_column($parts, 1);

        return [min($starts), max($ends)];
    }

    /** 'H:i' plus whole hours, clamped at the end of the day rather than wrapping past midnight. */
    private static function addHours(string $hhmm, int $hours): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return sprintf('%02d:%02d', min(23, $h + $hours), $m);
    }

    private static function minutesBetween(string $a, string $b): int
    {
        [$ah, $am] = array_map('intval', explode(':', $a));
        [$bh, $bm] = array_map('intval', explode(':', $b));

        return abs(($ah * 60 + $am) - ($bh * 60 + $bm));
    }

    /** "8am" / "8:30am" from 'H:i'. */
    private static function formatTime(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        $suffix = $h >= 12 ? 'pm' : 'am';
        $h12 = $h % 12;
        if ($h12 === 0) {
            $h12 = 12;
        }

        return $m === 0 ? "{$h12}{$suffix}" : sprintf('%d:%02d%s', $h12, $m, $suffix);
    }

    private static function partOfDayPhrase(string $part, bool $spanish): string
    {
        return match ($part) {
            'morning' => $spanish ? 'por la mañana' : 'in the morning',
            'afternoon' => $spanish ? 'por la tarde' : 'in the afternoon',
            'evening' => $spanish ? 'por la noche' : 'in the evening',
            default => $spanish ? 'en cualquier momento' : 'any time',
        };
    }
}
