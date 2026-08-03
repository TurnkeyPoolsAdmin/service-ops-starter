<?php

namespace App\Services\Reporting;

use App\Enums\JobStatus;
use App\Filament\Resources\Jobs\JobResource;
use App\Models\Job;
use App\Support\Csv;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Every cancellation, one row per job, for offline analysis in Excel.
 *
 * "Cancellation" here means BOTH closed-without-work statuses — `canceled` (we
 * had the job and it fell through) and `lost` (the lead never converted). They
 * are separate statuses with separate reason columns (`cancellation_reason` vs
 * `lost_reason`), and the owner needs to see them together to answer "why do we
 * lose work", so the export carries both with a Status column to split on.
 *
 * Test jobs are excluded — they are not real lost revenue. Soft-deleted jobs are
 * excluded too (the Issues queue covers deletions; a deleted job is a different
 * question from a canceled one).
 */
class CancellationsExport
{
    public const FILENAME = 'cancellations';

    /** @return array<int, string> */
    public function header(): array
    {
        return [
            'Job #',
            'Status',
            'Reason',
            'Customer',
            'Phone',
            'Location',
            'Canceled date',
            'Canceled at (PT)',
            'Canceled by',
            'Days open',
            'Created date',
            'Vehicle',
            'Glass position',
            'Quote',
            'Amount collected',
            'Refund owed',
            'Was scheduled',
            'Scheduled for (PT)',
            'Times rescheduled',
            'Technician',
            'Part number(s)',
            'Part ordered',
            'Part cost',
            'Part return needed',
            'Part returned',
            'Labor only',
            'Insurance',
            'Salesperson',
            'Lead source',
            'Service address',
            'City',
            'Intake note',
            'Job link',
            'Quo conversation link',
        ];
    }

    /** @return array<int, array<int, mixed>> */
    public function rows(): array
    {
        $jobs = Job::query()
            ->with(['entity', 'customer', 'technician'])
            ->whereIn('status', [JobStatus::Canceled->value, JobStatus::Lost->value])
            ->where('is_test', false)
            ->orderByDesc('canceled_at')
            ->get();

        $actors = $this->cancelActors($jobs);

        return $jobs->map(fn (Job $job) => $this->row($job, $actors))->all();
    }

    /**
     * @param  array<int, string>  $actors
     * @return array<int, mixed>
     */
    private function row(Job $job, array $actors): array
    {
        $collected = (int) ($job->amount_collected_cents ?? 0);

        return [
            $job->job_number,
            $job->status?->label(),
            $this->reason($job),
            $job->customer_name_snapshot ?: $job->customer?->full_name,
            $job->customer_phone_snapshot ?: $job->customer?->phone,
            $job->entity?->name,
            Csv::date($job->canceled_at),
            Csv::dateTime($job->canceled_at),
            $actors[$job->id] ?? null,
            $this->daysOpen($job),
            Csv::date($job->created_at),
            $job->vehicleLabel(),
            $job->glass_position,
            Csv::money($job->quote_amount_cents),
            Csv::money($collected),
            // Money we took on a job that never happened has to go back out.
            $collected > 0,
            $job->last_scheduled_at !== null || $job->scheduled_at !== null,
            Csv::dateTime($job->scheduled_at),
            (int) ($job->rescheduled_count ?? 0),
            $job->technician?->name,
            implode(' | ', $job->partLabels()),
            $job->parts_ordered_at !== null,
            Csv::money($job->part_cost_cents),
            $job->part_return_needed_at !== null,
            $job->part_returned_at !== null,
            (bool) $job->labor_only,
            (bool) $job->is_insurance,
            $job->bookingSalespersonName(),
            $job->external_source ?: 'manual',
            $job->service_address,
            $job->city,
            $job->notes,
            JobResource::getUrl('view', ['record' => $job->id], panel: 'admin'),
            $job->customer?->quo_conversation_url,
        ];
    }

    /**
     * The reason lives in a different column per status — `cancellation_reason`
     * for a canceled job, `lost_reason` for a lost lead. Read whichever the
     * status wrote, falling back to the other so an older row that only ever
     * filled one of them still reports its reason.
     */
    private function reason(Job $job): ?string
    {
        $reason = $job->status === JobStatus::Lost
            ? ($job->lost_reason ?: $job->cancellation_reason)
            : ($job->cancellation_reason ?: $job->lost_reason);

        return $reason ?: null;
    }

    /** Calendar days from intake to cancellation — how long we held the lead. */
    private function daysOpen(Job $job): ?int
    {
        if ($job->canceled_at === null || $job->created_at === null) {
            return null;
        }

        return (int) $job->created_at->diffInDays($job->canceled_at);
    }

    /**
     * Who canceled each job, read from the activity log (the job row itself only
     * stores the timestamp and reason, never the actor). One batched query keyed
     * by job id, so the export stays a fixed number of queries — the per-job
     * lookup this replaces would have been an N+1 across every cancellation.
     *
     * @param  Collection<int, Job>  $jobs
     * @return array<int, string>
     */
    private function cancelActors(Collection $jobs): array
    {
        if ($jobs->isEmpty()) {
            return [];
        }

        return Activity::query()
            ->where('subject_type', Job::class)
            ->whereIn('subject_id', $jobs->pluck('id'))
            ->whereIn('event', ['canceled', 'lost'])
            ->with('causer')
            ->orderBy('id')
            ->get()
            // Latest wins: a job reopened and canceled again should report the
            // person who canceled it the last time, not the first.
            ->mapWithKeys(fn (Activity $a) => [$a->subject_id => $a->causer?->name])
            ->filter()
            ->all();
    }

    public function toCsv(): string
    {
        return Csv::build($this->header(), $this->rows());
    }
}
