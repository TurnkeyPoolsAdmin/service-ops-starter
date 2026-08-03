<?php

namespace App\Services\Telegram;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\Job;
use App\Support\Timezone;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Supplies the "prepay these parts" section of OpsDigest: every job whose part
 * comes from an "Other" supplier (a dealer / one-off place we have no account
 * with) that we've ordered but NOT yet prepaid — the money has to go out before
 * the dealer releases the glass (Job::scopePrepayPending).
 *
 * Scoped to TODAY's and TOMORROW's appointments only, grouped by day. A part for
 * an appointment further out is real but not yet actionable, and listing it three
 * times a day for a week trains the group to skim past the section (owner's call,
 * 2026-07-30). It appears the moment its job is a day away.
 *
 * section() returns null when nothing is outstanding for either day, so the
 * digest drops the section rather than printing an empty heading.
 */
class PrepayPartsAlert
{
    public function section(): ?string
    {
        $groups = [];

        foreach ([['Today', Timezone::now()], ['Tomorrow', Timezone::now()->addDay()]] as [$label, $date]) {
            $jobs = $this->pendingJobs($date);

            if ($jobs->isNotEmpty()) {
                $groups[] = [$label, $date, $jobs];
            }
        }

        if ($groups === []) {
            return null;
        }

        $total = array_sum(array_map(fn (array $g) => $g[2]->count(), $groups));

        $out = ['⚠️ <b>Prepay these parts</b> ('.$total.')'];

        foreach ($groups as [$label, $date, $jobs]) {
            $out[] = '<i>'.e($label).' · '.e($date->format('D, M j')).'</i>';

            foreach ($jobs as $job) {
                $out[] = '• '.$this->jobRow($job);
            }
        }

        return implode("\n", $out);
    }

    /** Ordered-but-unpaid "Other"-supplier jobs scheduled on one local day. */
    public function pendingJobs(CarbonInterface $localDate): Collection
    {
        return Job::prepayPending()
            ->scheduledOnLocalDate($localDate)
            ->with(['entity', 'technician'])
            ->orderBy('scheduled_at')
            ->get();
    }

    private function jobRow(Job $job): string
    {
        $name = $this->jobLink($job);
        $loc = e($job->entity?->code ?? '—');
        $supplier = e($job->warehouse_other ?: 'Other');
        $when = Timezone::toDisplay($job->scheduled_at)?->format('g:ia');
        $part = filled($job->part_number) ? ' · '.e($job->part_number) : '';
        $whenStr = $when ? ' — '.e($when) : '';

        return "{$name} — {$loc}{$whenStr} · {$supplier}{$part}";
    }

    private function jobLink(Job $job): string
    {
        $name = e($job->customer_name_snapshot ?: 'Unknown');
        $url = JobResource::getUrl('view', ['record' => $job]);

        return "<a href=\"{$url}\">{$name}</a>";
    }
}
