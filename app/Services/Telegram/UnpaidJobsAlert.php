<?php

namespace App\Services\Telegram;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\Job;
use App\Support\Money;
use App\Support\Timezone;
use Illuminate\Support\Collection;

/**
 * Supplies the "completed jobs we still haven't been paid for" section of
 * OpsDigest: every work-complete job with money still outstanding — nothing
 * collected yet, or only a partial deposit against the quote
 * (Job::scopeAwaitingPayment). Posted in all three daily digests (7am / 12pm /
 * 5pm) so a finished job gets chased until the money lands.
 *
 * section() returns null when nothing is outstanding, so the digest drops the
 * section rather than printing an empty heading.
 */
class UnpaidJobsAlert
{
    public function section(): ?string
    {
        $jobs = $this->jobs();

        if ($jobs->isEmpty()) {
            return null;
        }

        $total = $jobs->sum(fn (Job $job) => $job->balanceDueCents());
        $owed = $total > 0 ? ' — '.Money::format($total).' owed' : '';

        $out = ['💵 <b>Completed, payment outstanding</b> ('.$jobs->count().$owed.')'];

        foreach ($jobs as $job) {
            $name = $this->jobLink($job);
            $loc = e($job->entity?->code ?? '—');
            $balance = $job->balanceDueCents();
            $amount = $balance > 0 ? Money::format($balance) : 'no quote';
            $partial = $job->isPartiallyPaid() ? ' (partial)' : '';
            $done = Timezone::toDisplay($job->work_completed_at)?->format('D M j');
            $doneStr = $done ? ' · done '.e($done) : '';
            $out[] = "• {$name} — {$loc} — {$amount}{$partial}{$doneStr}";
        }

        return implode("\n", $out);
    }

    public function jobs(): Collection
    {
        return Job::awaitingPayment()
            ->with(['entity', 'technician'])
            ->orderBy('work_completed_at')
            ->get();
    }

    private function jobLink(Job $job): string
    {
        $name = e($job->customer_name_snapshot ?: 'Unknown');
        $url = JobResource::getUrl('view', ['record' => $job]);

        return "<a href=\"{$url}\">{$name}</a>";
    }
}
