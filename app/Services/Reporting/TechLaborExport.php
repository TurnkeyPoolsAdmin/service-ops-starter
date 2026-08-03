<?php

namespace App\Services\Reporting;

use App\Enums\JobStatus;
use App\Filament\Resources\Jobs\JobResource;
use App\Models\Job;
use App\Models\WarrantyClaim;
use App\Support\Csv;

/**
 * Labor paid to each technician, one row per job, for offline analysis.
 *
 * Two kinds of row, split by the "Type" column:
 *  - "Job"            — the labor on a normal job (base pay + any additional).
 *  - "Warranty visit" — the optional extra pay on a return visit, which settles
 *                       through its own `warranty_payout` settlement and never
 *                       touches the job's `tech_pay_amount_cents`. Without these
 *                       rows the per-tech totals would understate what we paid.
 *
 * ⚠️ Amount is `expected_tech_pay_cents` (base + additional), NOT the raw
 * `tech_pay_amount_cents` column. Those two agree only AFTER settlement, because
 * `markTechPaid` folds the additional labor into the base and zeroes
 * `additional_labor_cents`. Reading the raw column would silently under-report
 * every job that is complete but not yet settled.
 *
 * Tech-fronted parts are reported in their own column and deliberately excluded
 * from the labor amount — that is a reimbursement of a part cost we already
 * count, not pay for work.
 */
class TechLaborExport
{
    public const FILENAME = 'tech-labor';

    /** @return array<int, string> */
    public function header(): array
    {
        return [
            'Job #',
            'Car type',
            'Technician',
            'Amount paid',
            'Pay status',
            'Paid date',
            'Type',
            'Location',
            'Work completed date',
            'Job status',
            'Glass position',
            'Labor only',
            'Additional labor',
            'Additional pay reason',
            'Parts fronted by tech',
            'Total to tech',
            'Quote',
            'Part cost',
            'Gross margin',
            'Job link',
        ];
    }

    /** @return array<int, array<int, mixed>> */
    public function rows(): array
    {
        $rows = [...$this->jobRows(), ...$this->warrantyRows()];

        // Newest work first, blanks last, so the sheet opens on recent activity.
        usort($rows, fn (array $a, array $b) => ($b[8] ?? '') <=> ($a[8] ?? ''));

        return $rows;
    }

    /**
     * Labor on normal jobs: any job with a tech assigned that has either been
     * worked or already settled. Canceled/lost jobs carry no labor, and test
     * jobs are not real money.
     *
     * @return array<int, array<int, mixed>>
     */
    private function jobRows(): array
    {
        return Job::query()
            ->with(['entity', 'technician'])
            ->whereNotNull('technician_id')
            ->where('is_test', false)
            ->whereNotIn('status', [JobStatus::Canceled->value, JobStatus::Lost->value])
            ->where(fn ($q) => $q->whereNotNull('work_completed_at')->orWhereNotNull('tech_paid_at'))
            ->orderByDesc('work_completed_at')
            ->get()
            ->map(function (Job $job) {
                $paid = $job->tech_paid_at !== null;

                return [
                    $job->job_number,
                    $job->vehicleLabel(),
                    $job->technician?->name,
                    Csv::money($job->expected_tech_pay_cents),
                    $paid ? 'Paid' : 'Not yet paid',
                    Csv::date($job->tech_paid_at),
                    'Job',
                    $job->entity?->name,
                    Csv::date($job->work_completed_at),
                    $job->status?->label(),
                    $job->glass_position,
                    (bool) $job->labor_only,
                    Csv::money($job->additional_labor_cents),
                    $job->additional_labor_reason,
                    Csv::money($job->tech_parts_reimbursement_cents),
                    Csv::money($job->total_owed_to_tech_cents),
                    Csv::money($job->quote_amount_cents),
                    Csv::money($job->part_cost_cents),
                    Csv::money($job->gross_margin_cents),
                    JobResource::getUrl('view', ['record' => $job->id], panel: 'admin'),
                ];
            })
            ->all();
    }

    /**
     * Extra pay on warranty return visits. Only claims that actually carry pay —
     * a warranty visit is unpaid by default, so a claim with no `tech_pay_cents`
     * is not a labor row at all.
     *
     * The visit generates no revenue by design, so quote / part cost / gross
     * margin stay blank rather than repeating the original job's numbers, which
     * would double-count that job's money in any total.
     *
     * @return array<int, array<int, mixed>>
     */
    private function warrantyRows(): array
    {
        return WarrantyClaim::query()
            ->with(['job.entity', 'technician'])
            ->where('tech_pay_cents', '>', 0)
            ->whereIn('status', [WarrantyClaim::STATUS_SCHEDULED, WarrantyClaim::STATUS_RESOLVED])
            ->whereNotNull('technician_id')
            ->orderByDesc('resolved_at')
            ->get()
            ->map(function (WarrantyClaim $claim) {
                $paid = $claim->tech_paid_at !== null;
                $job = $claim->job;

                return [
                    $job?->job_number,
                    $job?->vehicleLabel(),
                    $claim->technician?->name,
                    Csv::money($claim->tech_pay_cents),
                    $paid ? 'Paid' : 'Not yet paid',
                    Csv::date($claim->tech_paid_at),
                    'Warranty visit',
                    $job?->entity?->name,
                    Csv::date($claim->resolved_at ?? $claim->scheduled_at),
                    ucfirst((string) $claim->status),
                    $job?->glass_position,
                    (bool) ($job?->labor_only),
                    null,
                    null,
                    null,
                    Csv::money($claim->tech_pay_cents),
                    null,
                    null,
                    null,
                    $job ? JobResource::getUrl('view', ['record' => $job->id], panel: 'admin') : null,
                ];
            })
            ->all();
    }

    public function toCsv(): string
    {
        return Csv::build($this->header(), $this->rows());
    }
}
