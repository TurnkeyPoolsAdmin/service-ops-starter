<?php

namespace App\Services\Telegram;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\Job;
use Illuminate\Support\Collection;

/**
 * Supplies the "on hold — waiting on the customer" section of OpsDigest: every
 * job that still needs scheduling but is parked because we are waiting to hear
 * back from the customer (Job::scopeOnSchedulingHold).
 *
 * A hold takes the job OUT of the needs-scheduling section, so without this it
 * would leave the digest entirely and the money would go quiet — which is the
 * exact way a lead gets forgotten. So a held job keeps being named, every
 * digest, with the reason and how long it has sat: the reader sees "still on
 * hold, 6 days" and knows to chase the customer or mark the job lost.
 *
 * It is a LIVE list with no per-job stamp (same shape as the same-day-parts and
 * review-call nags): releasing the hold or scheduling the job drops it off by
 * itself on the next digest. Oldest hold first — the one most likely to be dead.
 *
 * section() returns null when nothing is on hold, so the digest drops the
 * heading rather than printing it empty.
 */
class SchedulingHoldAlert
{
    public function section(): ?string
    {
        $jobs = $this->heldJobs();

        if ($jobs->isEmpty()) {
            return null;
        }

        $out = ['⏸️ <b>On hold — waiting on the customer</b> ('.$jobs->count().')'];

        foreach ($jobs as $job) {
            $name = $this->jobLink($job);
            $loc = e($job->entity?->code ?? '—');
            $reason = filled($job->scheduling_hold_reason)
                ? ' · '.e($job->scheduling_hold_reason)
                : '';

            $out[] = "• {$name} — {$loc} · {$this->heldFor($job)}{$reason}";
        }

        return implode("\n", $out);
    }

    public function heldJobs(): Collection
    {
        return Job::onSchedulingHold()
            ->with('entity')
            ->orderBy('scheduling_hold_at')
            ->get();
    }

    /** "on hold since today" / "on hold 6 days". */
    private function heldFor(Job $job): string
    {
        $days = $job->schedulingHoldDays();

        return match (true) {
            $days === null, $days === 0 => 'on hold since today',
            $days === 1 => 'on hold 1 day',
            default => 'on hold '.$days.' days',
        };
    }

    private function jobLink(Job $job): string
    {
        $name = e($job->customer_name_snapshot ?: 'Unknown');
        $url = JobResource::getUrl('view', ['record' => $job]);

        return "<a href=\"{$url}\">{$name}</a>";
    }
}
