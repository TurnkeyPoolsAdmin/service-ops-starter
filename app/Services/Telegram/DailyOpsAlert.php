<?php

namespace App\Services\Telegram;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\Job;
use App\Support\Timezone;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Supplies the two "needs a human today" ops queues to OpsDigest — jobs still
 * needing a schedule, and scheduled jobs whose part must be ordered now
 * (Job::scopeNeedsScheduling / scopePartsReadyToOrder, the same sets the board
 * badges count).
 *
 * The needs-scheduling queue splits on Job::isRequestedTodayOrOverdue():
 *   - URGENT — unscheduled jobs the customer asked for TODAY (or a day already
 *     past). At 7am these post as their OWN message (urgentMessage) so the
 *     time-critical half can't get lost mid-list.
 *   - the rest — tomorrow or later, or no real requested date.
 * At midday and evening OpsDigest merges the two back into one section with the
 * urgent rows flagged: an unscheduled same-day job is MORE urgent at noon, not
 * less, and a second standalone text every slot is just noise.
 *
 * Every section renderer returns null when its queue is empty, so OpsDigest can
 * drop the section rather than print "none" three times a day.
 */
class DailyOpsAlert
{
    /** Unscheduled jobs the customer asked for today, or a day already past. */
    public function urgentJobs(): Collection
    {
        return $this->needsSchedulingJobs()
            ->filter(fn (Job $job) => $job->isRequestedTodayOrOverdue())
            ->values();
    }

    /** Everything else still needing a schedule — tomorrow+, or no real date. */
    public function laterJobs(): Collection
    {
        return $this->needsSchedulingJobs()
            ->reject(fn (Job $job) => $job->isRequestedTodayOrOverdue())
            ->values();
    }

    public function needsSchedulingJobs(): Collection
    {
        return Job::needsScheduling()
            ->with('entity')
            ->orderBy('created_at')
            ->get();
    }

    public function partsToOrder(): Collection
    {
        return Job::partsReadyToOrder()
            ->with(['entity', 'technician'])
            ->orderBy('scheduled_at')
            ->get();
    }

    /** The standalone 7am URGENT message. */
    public function urgentMessage(Collection $jobs): string
    {
        $today = Timezone::now()->format('D, M j');

        return trim(implode("\n", [
            "🚨 <b>URGENT — needs scheduling TODAY</b> ({$today})",
            '',
            $this->needsSchedulingSection($jobs, 'Requested for today') ?? '',
        ]));
    }

    /**
     * @param  bool  $flagUrgent  prefix same-day/overdue rows with 🚨 (used when
     *                            the urgent jobs are merged into this section
     *                            rather than posted as their own message)
     */
    public function needsSchedulingSection(Collection $jobs, string $heading, bool $flagUrgent = false): ?string
    {
        if ($jobs->isEmpty()) {
            return null;
        }

        $out = ['📅 <b>'.e($heading).'</b> ('.$jobs->count().')'];

        foreach ($jobs as $job) {
            $flag = $flagUrgent && $job->isRequestedTodayOrOverdue() ? '🚨 ' : '';
            $name = $this->jobLink($job);
            $loc = e($job->entity?->code ?? '—');
            $out[] = "• {$flag}{$name} — {$loc}{$this->requestedSuffix($job)}";
        }

        return implode("\n", $out);
    }

    /** " — requested Tue, Jul 21" (or the raw free text we were given). */
    private function requestedSuffix(Job $job): string
    {
        $date = $job->requestedDate();

        $label = $date !== null
            ? Carbon::parse($date)->format('D, M j')
            : trim((string) $job->requested_day);

        return $label === '' ? '' : ' — requested '.e($label);
    }

    public function partsSection(Collection $jobs): ?string
    {
        if ($jobs->isEmpty()) {
            return null;
        }

        $out = ['🛠️ <b>Parts — ready to order</b> ('.$jobs->count().')'];

        foreach ($jobs as $job) {
            $name = $this->jobLink($job);
            $loc = e($job->entity?->code ?? '—');
            $when = Timezone::toDisplay($job->scheduled_at)?->format('D g:ia');
            $part = filled($job->part_number) ? ' · '.e($job->part_number) : '';
            $whenStr = $when ? ' — '.e($when) : '';
            $out[] = "• {$name} — {$loc}{$whenStr}{$part}";
        }

        return implode("\n", $out);
    }

    private function jobLink(Job $job): string
    {
        $name = e($job->customer_name_snapshot ?: 'Unknown');
        $url = JobResource::getUrl('view', ['record' => $job]);

        return "<a href=\"{$url}\">{$name}</a>";
    }
}
