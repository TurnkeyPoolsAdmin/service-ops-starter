<?php

namespace App\Services\Telegram;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\Job;
use App\Services\Messaging\TemplateRenderer;
use App\Support\AlertWindow;
use App\Support\Timezone;
use Illuminate\Support\Collection;

/**
 * Builds the "these jobs are overdue to be marked complete" alert posted to the
 * Telegram group chat: every still-scheduled job whose arrival window closed at
 * least the grace period ago (config('glass.telegram.overdue_grace_hours'), 1h)
 * and which hasn't been marked work-complete. Example: an 8am–10am window job
 * still not complete at 11am.
 *
 * Because the window length is per-job (exact-time / custom hours), the SQL only
 * coarse-filters on scheduled_at and the exact threshold is applied in PHP via
 * Job::scheduledWindowEndUtc().
 *
 * ⚠️ This REPEATS, it does not ping once (changed 2026-07-30). A job keeps
 * alerting every `overdue_repeat_hours` (1h) until someone marks it complete —
 * jobs.overdue_completion_alerted_at is now "when we last said something", not
 * "we already said it", and the command re-stamps it on every send. A single
 * ping was too easy to scroll past on a job that then sat open all day.
 *
 * The sweep is silent outside App\Support\AlertWindow (7am-7pm local): a job
 * overdue at 9pm resumes alerting at 7am rather than pinging the group all
 * night. Safe to gate because overdue_completion_alerted_at is only stamped on
 * a successful send. A reschedule still nulls the stamp, so a pushed-back job
 * starts its clock over.
 */
class OverdueCompletionAlert
{
    /** Jobs past their window + grace, still not complete, due to alert again. */
    public function dueJobs(): Collection
    {
        if (! AlertWindow::open()) {
            return collect();
        }

        $grace = $this->graceHours();
        $repeatBefore = now()->subHours($this->repeatHours());

        // Coarse SQL prefilter: the window end is never before scheduled_at, so
        // anything due (now >= windowEnd + grace) must have scheduled_at <= now
        // - grace. The precise per-job check happens in the PHP filter below.
        return Job::scheduled()
            ->whereNull('work_completed_at')
            ->where(fn ($q) => $q->whereNull('overdue_completion_alerted_at')
                ->orWhere('overdue_completion_alerted_at', '<=', $repeatBefore))
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now()->subHours($grace))
            ->with(['entity', 'technician'])
            ->orderBy('scheduled_at')
            ->get()
            ->filter(fn (Job $job) => now()->gte($job->scheduledWindowEndUtc()->copy()->addHours($grace)))
            ->values();
    }

    public function render(Collection $jobs): string
    {
        $grace = $this->graceHours();
        $today = Timezone::now()->format('D, M j');

        $lines = [
            "⏰ <b>Service Ops — Overdue completion</b> ({$today})",
            '',
            "Not marked complete {$grace}h+ after the window (".$jobs->count().'):',
        ];

        foreach ($jobs as $job) {
            $name = $this->jobLink($job);
            $loc = e($job->entity?->code ?? '—');
            $day = Timezone::toDisplay($job->scheduled_at)?->format('D');
            $window = TemplateRenderer::formatWindow($job);
            $whenStr = $window ? ' — '.e(trim($day.' '.$window)) : '';
            $tech = filled($job->technician?->name) ? ' · '.e($job->technician->name) : '';
            $lines[] = "• {$name} — {$loc}{$whenStr}{$tech}";
        }

        return trim(implode("\n", $lines));
    }

    private function graceHours(): int
    {
        return (int) config('glass.telegram.overdue_grace_hours', 1);
    }

    private function repeatHours(): int
    {
        return (int) config('glass.telegram.overdue_repeat_hours', 1);
    }

    private function jobLink(Job $job): string
    {
        $name = e($job->customer_name_snapshot ?: 'Unknown');
        $url = JobResource::getUrl('view', ['record' => $job]);

        return "<a href=\"{$url}\">{$name}</a>";
    }
}
