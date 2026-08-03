<?php

namespace App\Services\AiScheduling;

use App\Models\Employee;
use App\Models\Job;
use App\Models\WarrantyClaim;
use App\Support\Timezone;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Reads a technician's real existing schedule for the AI negotiation — a soft
 * load score per part of day (TechDayLoad), plus a hard-conflict check for an
 * exact-time request. There is no other conflict/busy model in the app
 * (JobCalendar only lays out overlap visually); this is net new domain logic.
 * See docs/ai-scheduling-spec.md §1.2.
 */
final class TechAvailability
{
    /** The tech's open jobs (+ scheduled warranty visits) on a local date, bucketed by part of day. */
    public function loadFor(Employee $tech, string $localDate): TechDayLoad
    {
        $counts = ['morning' => 0, 'afternoon' => 0, 'evening' => 0];

        foreach ($this->scheduledTimesFor($tech, $localDate) as $hhmm) {
            $part = SchedulingConstraint::partOfDayForTime($hhmm);
            if (array_key_exists($part, $counts)) {
                $counts[$part]++;
            }
        }

        return new TechDayLoad($counts['morning'], $counts['afternoon'], $counts['evening']);
    }

    /**
     * A genuine conflict: the tech already has an EXACT-TIME job whose
     * arrival window overlaps an exact-time request's window. Anything softer
     * (two window/flexible jobs the same morning) is left for the tech to
     * judge when we ask them — that's what TechDayLoad's note is for.
     */
    public function hasHardConflict(Employee $tech, SchedulingConstraint $constraint): bool
    {
        if ($constraint->firmness !== SchedulingConstraint::FIRM_EXACT) {
            return false;
        }

        $windowHours = (int) config('glass.appointment_window_hours', 2);
        $requestedStart = Carbon::parse("{$constraint->date} {$constraint->exactTime}", Timezone::display());
        $requestedEnd = $requestedStart->copy()->addHours($windowHours);

        foreach ($this->existingJobs($tech, $constraint->date) as $job) {
            if (! $job->scheduled_exact_time || $job->scheduled_at === null) {
                continue;
            }

            $existingStart = Timezone::toDisplay($job->scheduled_at);
            $existingEnd = $existingStart->copy()->addHours(
                (int) config('glass.appointment_window_hours', 2)
            );

            if ($requestedStart->lt($existingEnd) && $existingStart->lt($requestedEnd)) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, Job> */
    private function existingJobs(Employee $tech, string $localDate): Collection
    {
        return Job::query()
            ->open()
            ->whereNull('work_completed_at')
            ->where('technician_id', $tech->id)
            ->scheduledOnLocalDate($localDate)
            ->get();
    }

    /** @return list<string> 'H:i' wall-clock times the tech is already booked that local date — jobs + warranty visits. */
    private function scheduledTimesFor(Employee $tech, string $localDate): array
    {
        $jobTimes = $this->existingJobs($tech, $localDate)
            ->pluck('scheduled_at')
            ->map(fn ($utc) => Timezone::toDisplay($utc)->format('H:i'))
            ->all();

        $warrantyTimes = WarrantyClaim::query()
            ->where('status', WarrantyClaim::STATUS_SCHEDULED)
            ->where('technician_id', $tech->id)
            ->whereNotNull('scheduled_at')
            ->get()
            ->filter(fn (WarrantyClaim $claim) => Timezone::toDisplay($claim->scheduled_at)->format('Y-m-d') === $localDate)
            ->map(fn (WarrantyClaim $claim) => Timezone::toDisplay($claim->scheduled_at)->format('H:i'))
            ->all();

        return [...$jobTimes, ...$warrantyTimes];
    }
}
