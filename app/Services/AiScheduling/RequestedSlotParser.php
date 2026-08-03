<?php

namespace App\Services\AiScheduling;

use App\Enums\TimePreference;
use App\Models\Job;
use App\Support\BusinessDays;
use App\Support\Timezone;

/**
 * Builds a SchedulingConstraint from what a job's intake actually captured —
 * `jobs.requested_day` (already Y-m-d from the intake DatePicker; legacy free
 * text reads as null via Job::requestedDate()) and the structured
 * `requested_time_preference` / `requested_after_time` / free-text
 * `requested_time`. Deterministic
 * first; a caller-supplied LLM fallback only ever handles the "Specific"
 * preference whose free text doesn't contain a parseable clock time.
 *
 * The dispatcher always sees and can edit the resolved constraint in the
 * launch modal (docs/ai-scheduling-spec.md §5.1) — this is a convenience,
 * never an authority.
 */
final class RequestedSlotParser
{
    public function __construct(private readonly ?SlotTimeInterpreter $llmFallback = null) {}

    public function parse(Job $job): SchedulingConstraint
    {
        $date = $job->requestedDate() ?? BusinessDays::nextBusinessDay(Timezone::now())->format('Y-m-d');
        $preference = $job->timePreference();

        if ($preference === TimePreference::Specific) {
            return $this->parseSpecific($job, $date);
        }

        if ($preference === TimePreference::After) {
            return $this->parseAfter($job, $date);
        }

        return match ($preference) {
            TimePreference::Morning => SchedulingConstraint::window($date, 'morning'),
            TimePreference::Afternoon => SchedulingConstraint::window($date, 'afternoon'),
            TimePreference::Evening => SchedulingConstraint::window($date, 'evening'),
            default => SchedulingConstraint::flexible($date), // None, Asap
        };
    }

    /**
     * "After 10am": the structured `requested_after_time` column, falling back
     * to reading the hour out of the free text for a legacy job. No LLM step
     * here — an After with no readable hour is simply a flexible day, which is
     * what the customer effectively told us.
     */
    private function parseAfter(Job $job, string $date): SchedulingConstraint
    {
        $time = $job->requestedAfterTime();

        return $time === null
            ? SchedulingConstraint::flexible($date)
            : SchedulingConstraint::after($date, $time);
    }

    private function parseSpecific(Job $job, string $date): SchedulingConstraint
    {
        $time = self::extractClockTime($job->requested_time);
        if ($time !== null) {
            return SchedulingConstraint::exact($date, $time);
        }

        if ($this->llmFallback !== null) {
            $interpreted = $this->llmFallback->interpretRequestedTime((string) $job->requested_time, $date);
            if ($interpreted !== null) {
                return $interpreted;
            }
        }

        return SchedulingConstraint::flexible($date);
    }

    /**
     * Best-effort extraction of a single clock time from free text ("2pm",
     * "10:30", "after 3", "a las 3") into 'H:i', or null when nothing
     * parseable is found. A bare hour with no am/pm marker is guessed from
     * shop hours: 1-6 reads as afternoon/evening ("after 3"), 7-11 as morning.
     */
    public static function extractClockTime(?string $text): ?string
    {
        $value = strtolower(trim((string) $text));
        if ($value === '') {
            return null;
        }

        if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/', $value, $m) !== 1) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $meridiem = isset($m[3]) && $m[3] !== '' ? $m[3] : null;

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        if ($meridiem === null) {
            if ($hour === 12) {
                $meridiem = 'pm';
            } elseif ($hour >= 1 && $hour <= 6) {
                $meridiem = 'pm';
            } elseif ($hour >= 7 && $hour <= 11) {
                $meridiem = 'am';
            }
            // hour 0 or already > 12 (24h-style) — leave as given.
        }

        if ($meridiem === 'pm' && $hour < 12) {
            $hour += 12;
        }
        if ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }
}
