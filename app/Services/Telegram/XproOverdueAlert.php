<?php

namespace App\Services\Telegram;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\Job;
use App\Support\Money;
use App\Support\Timezone;
use Illuminate\Support\Collection;

/**
 * Builds the WEEKLY WEDNESDAY digest of Owed-from-Xpro money that's now PAST its
 * collection deadline — noon the Wednesday after the Friday it was first owed
 * (Job::isXproCollectionOverdue). Paired with the Friday digest (WeeklyXproAlert):
 *
 *   Friday    — everything currently outstanding, however recent. Routine.
 *   Wednesday — only what is still uncollected from LAST week, i.e. past its
 *               deadline. The chase list. The same jobs also sit on the
 *               super-admin Issues queue via issues:sweep.
 *
 * ⚠️ This is a LIVE digest, not a once-per-job ping (changed 2026-07-30). It
 * reposts the whole overdue list every Wednesday until the money comes back, so
 * it is deliberately NOT filtered on jobs.xpro_overdue_alerted_at — the command
 * still stamps that column, but it now records "when we last chased this" rather
 * than gating the send. A single ping let a job go quiet while still owing us.
 */
class XproOverdueAlert
{
    /** Every Owed-from-Xpro job past its collection deadline. */
    public function dueJobs(): Collection
    {
        return Job::owedFromXpro()
            ->with(['entity', 'customer', 'payments'])
            ->orderBy('payment_received_at')
            ->get()
            ->filter(fn (Job $job) => $job->isXproCollectionOverdue())
            ->values();
    }

    public function render(Collection $jobs): string
    {
        $today = Timezone::now()->format('D, M j');
        $total = $jobs->sum(fn (Job $job) => $job->xproOwedCents());

        $lines = [
            "⚠️ <b>Service Ops — Owed from Xpro OVERDUE</b> ({$today})",
            '',
            'Still not collected from last week — chase the Xpro team ('
                .$jobs->count().' — '.Money::format($total).'):',
        ];

        foreach ($jobs as $job) {
            $name = $this->jobLink($job);
            $loc = e($job->entity?->code ?? '—');
            $amount = Money::format($job->xproOwedCents());
            $method = e($job->xproMethodsLabel() ?? 'Xpro');
            $due = $job->xproCollectionDueAt()?->format('M j');
            $dueStr = $due ? " · was due {$due}" : '';
            $lines[] = "• {$name} — {$loc} — {$amount} · {$method}{$dueStr}";
        }

        return trim(implode("\n", $lines));
    }

    private function jobLink(Job $job): string
    {
        $name = e($job->customer_name_snapshot ?: 'Unknown');
        $url = JobResource::getUrl('view', ['record' => $job]);

        return "<a href=\"{$url}\">{$name}</a>";
    }
}
