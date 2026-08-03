<?php

namespace App\Services\Telegram;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\Job;
use App\Support\AlertWindow;
use App\Support\Timezone;

/**
 * Builds the "same-day job, part still not ordered" nag posted to the Telegram
 * group chat. Same-day scheduling no longer blocks on ordering the part (the
 * schedule modal shows a non-blocking warning instead — see
 * JobActions::sameDayOrderSection), so this recurring alert is what actually
 * keeps the part from getting forgotten: it reposts the live list every 30
 * minutes (alerts:telegram-same-day-parts-unordered) and drops a job the
 * moment it's marked ordered. No per-row stamp — it's a live digest, not a
 * one-time ping, so it keeps surfacing until dispatch orders the part.
 *
 * ⚠️ It is silent outside App\Support\AlertWindow (7am-7pm local), and that
 * gate is the whole reason AlertWindow exists. The 30-minute schedule knows
 * nothing about the time of day, and at 00:00 Pacific the calendar day rolls
 * over: every job scheduled for the coming day whose part is not ordered
 * instantly counts as "TODAY", so the group got a 🚨 post every 30 minutes
 * from midnight until someone woke up and ordered the part. Gating is safe
 * because the list is rebuilt live each run — nothing is lost, the same jobs
 * are simply named again at 7am, when a part can actually be ordered.
 *
 * build() returns null when nothing is outstanding so the caller stays quiet.
 */
class SameDayPartsUnorderedAlert
{
    public function build(): ?string
    {
        if (! AlertWindow::open()) {
            return null;
        }

        $jobs = Job::query()
            ->partsToOrder()
            ->scheduledToday()
            ->with(['entity', 'technician'])
            ->orderBy('scheduled_at')
            ->get();

        if ($jobs->isEmpty()) {
            return null;
        }

        $now = Timezone::now()->format('D, M j g:ia');

        $lines = [
            "🚨 <b>Service Ops — Part not ordered, job is TODAY</b> ({$now})",
            '',
            'Please order ASAP ('.$jobs->count().'):',
        ];

        foreach ($jobs as $job) {
            $lines[] = '• '.$this->jobLine($job);
        }

        return trim(implode("\n", $lines));
    }

    private function jobLine(Job $job): string
    {
        $name = $this->jobLink($job);
        $loc = e($job->entity?->code ?? '—');
        $time = Timezone::toDisplay($job->scheduled_at)?->format('g:ia');
        $timeStr = $time ? ' — '.e($time) : '';
        $tech = filled($job->technician?->name) ? ' · '.e($job->technician->name) : '';

        return "{$name} — {$loc}{$timeStr}{$tech}";
    }

    private function jobLink(Job $job): string
    {
        $name = e($job->customer_name_snapshot ?: 'Unknown');
        $url = JobResource::getUrl('view', ['record' => $job]);

        return "<a href=\"{$url}\">{$name}</a>";
    }
}
