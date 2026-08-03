<?php

namespace App\Services\Vendors;

use App\Models\Employee;
use App\Models\Job;
use App\Models\VendorDocumentLine;
use App\Support\Money;
use App\Support\PartNumber;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Ties one billed Mygrant order-history LINE to the job its part was for.
 *
 * Signals, in priority order (see class-level notes on each private method):
 *   1. Normalized part number equal — strong.
 *   2. Vehicle agreement (line's year RANGE contains the job's year, make
 *      equal, model prefix/fuzzy) — strong.
 *   3. Date proximity — implicit: only jobs within match_window_days of the
 *      line's confirmed/order date are ever candidates at all.
 *   4. Amount within +/-10% — CONFIRMS ONLY. A candidate that agrees on
 *      nothing but the dollar amount is never eligible for a suggestion, let
 *      alone an auto-link; it only nudges an already-eligible candidate's score.
 *   5. Customer_PO tech name vs. the job's technician — tiebreaker only, used
 *      to pick a winner among otherwise-tied eligible candidates.
 *
 * Auto-link (match_status = matched) fires ONLY when part number matches AND
 * vehicle agrees AND exactly one candidate satisfies both. Everything else
 * that has at least a partial signal becomes a `suggested` row (one-click
 * human confirm); a line with no plausible candidate at all is left
 * `unmatched`. Consumables (no parseable vehicle, under consumable_max_cents)
 * auto-route to `no_job` / shop_supplies without ever reaching a job at all.
 */
class VendorLineMatcher
{
    /** Sweep every fresh (never-touched) billed line; returns state => count. */
    public function run(): array
    {
        $tally = ['matched' => 0, 'suggested' => 0, 'no_job' => 0, 'unmatched' => 0];

        $lines = VendorDocumentLine::query()
            ->billed()
            ->where('match_status', VendorDocumentLine::MATCH_UNMATCHED)
            ->get();

        foreach ($lines as $line) {
            $state = $this->matchOne($line) ?? 'unmatched';
            $tally[$state] = ($tally[$state] ?? 0) + 1;
        }

        return $tally;
    }

    /** Try to resolve one billed line; returns the resulting state, or null when nothing changed. */
    public function matchOne(VendorDocumentLine $line): ?string
    {
        // Checked FIRST, ahead of every other signal. A line older than the day
        // Job records begin can never match anything, so reading its vehicle or
        // its amount only risks asserting something false about it — a cheap
        // no-vehicle line from 2025 would otherwise be filed as shop supplies.
        // "No job could exist yet" is the more fundamental fact.
        if ($this->predatesJobTracking($line)) {
            $this->markNoJob($line, 'pre_job_tracking', source: 'auto');

            return 'no_job';
        }

        if ($this->isUnparseableVehicle($line)) {
            $maxConsumable = (int) config('glass.vendors.mygrant.consumable_max_cents', 10000);

            if ((int) $line->line_total_cents <= $maxConsumable) {
                $this->markNoJob($line, 'shop_supplies', source: 'auto');

                return 'no_job';
            }

            // No vehicle, but a real dollar amount — the $775 case. Needs a
            // human look; not a job match, not obviously shop supplies either.
            return null;
        }

        $candidates = $this->candidateJobs($line);
        if ($candidates->isEmpty()) {
            return null;
        }

        $strong = $candidates->filter(fn (Job $job) => $this->partNumberMatches($line, $job) && $this->vehicleAgrees($line, $job));
        if ($strong->count() === 1) {
            $this->linkMatched($line, $strong->first());

            return 'matched';
        }

        $suggestion = $this->bestSuggestion($line, $candidates);
        if ($suggestion !== null) {
            $this->linkSuggested($line, $suggestion);

            return 'suggested';
        }

        return null;
    }

    // ---- Human actions (Filament page) ----

    /** Confirm what an auto-suggestion proposed. */
    public function confirmSuggestion(VendorDocumentLine $line, ?Employee $actor): void
    {
        if ($line->suggested_job_id === null) {
            return;
        }

        $job = Job::find($line->suggested_job_id);
        if ($job === null) {
            return;
        }

        $this->linkMatched($line, $job, actor: $actor, source: 'manual');
    }

    /** Manually pick the job — the human's word wins regardless of signal strength. */
    public function matchToJob(VendorDocumentLine $line, Job $job, ?Employee $actor): void
    {
        $this->linkMatched($line, $job, actor: $actor, source: 'manual');
    }

    /** No job applies — shop supplies, a returned part, or a human's judgment call. */
    public function markNoJob(VendorDocumentLine $line, string $reason, ?Employee $actor = null, string $source = 'manual'): void
    {
        $line->update([
            'job_id' => null,
            'suggested_job_id' => null,
            'match_status' => VendorDocumentLine::MATCH_NO_JOB,
            'match_source' => $source,
            'matched_at' => now(),
            'matched_by' => $actor?->id,
            'no_job_reason' => $reason,
            'variance_cents' => null,
        ]);
    }

    // ---- Core signals ----

    /**
     * True when this line is older than the day Job records begin
     * (glass.vendors.job_tracking_start). Both sides are plain 'Y-m-d' strings,
     * so a string compare is the correct comparison — no Carbon, no timezone.
     *
     * A line with NO date at all is never pre-tracking: we cannot show it is,
     * and wrongly excluding a dateable line is worse than leaving it in the
     * queue where a human can see it.
     */
    private function predatesJobTracking(VendorDocumentLine $line): bool
    {
        $start = config('glass.vendors.job_tracking_start');
        $date = $line->confirmed_date ?: $line->order_date;

        return $start !== null && $date !== null && $date < $start;
    }

    private function isUnparseableVehicle(VendorDocumentLine $line): bool
    {
        return $line->vehicle_years_from === null && $line->vehicle_make === null;
    }

    private function partNumberMatches(VendorDocumentLine $line, Job $job): bool
    {
        if ($line->part_number_base === null || blank($job->part_number)) {
            return false;
        }

        return PartNumber::normalizeBase($job->part_number) === $line->part_number_base;
    }

    /** Line's year RANGE contains the job's year; make equal; model prefix/fuzzy. */
    private function vehicleAgrees(VendorDocumentLine $line, Job $job): bool
    {
        if ($job->vehicle_year === null || $line->vehicle_years_from === null) {
            return false;
        }

        $year = (int) $job->vehicle_year;
        $to = $line->vehicle_years_to ?? 9999; // open-ended ("still current")
        if ($year < $line->vehicle_years_from || $year > $to) {
            return false;
        }

        if (blank($job->vehicle_make) || blank($line->vehicle_make)) {
            return false;
        }

        if (mb_strtolower(trim((string) $job->vehicle_make)) !== mb_strtolower(trim((string) $line->vehicle_make))) {
            return false;
        }

        $jobModel = mb_strtolower(trim((string) $job->vehicle_model));
        $lineModel = mb_strtolower(trim((string) $line->vehicle_model));
        if ($jobModel === '' || $lineModel === '') {
            // Make + year already agree; no model text to contradict it with.
            return true;
        }

        return str_starts_with($jobModel, $lineModel) || str_starts_with($lineModel, $jobModel)
            || str_contains($jobModel, $lineModel) || str_contains($lineModel, $jobModel);
    }

    /** CONFIRMS ONLY — never establishes eligibility by itself (see candidateScore). */
    private function amountConfirms(VendorDocumentLine $line, Job $job): bool
    {
        $jobCost = (int) ($job->part_cost_cents ?? 0);
        if ($jobCost <= 0) {
            return false;
        }

        $diff = abs((int) $line->line_total_cents - $jobCost);

        return $diff <= (int) round($jobCost * 0.10);
    }

    /** Customer_PO is freeform junk (": JOSE", ": oscar/Laura") — a loose substring check on the tech's first name. */
    private function techMatchesPo(VendorDocumentLine $line, Job $job): bool
    {
        $po = mb_strtolower((string) $line->customer_po);
        $techName = mb_strtolower(trim((string) $job->technician?->name));

        if ($po === '' || $techName === '') {
            return false;
        }

        $first = (string) (preg_split('/\s+/', $techName)[0] ?? '');

        return mb_strlen($first) >= 3 && str_contains($po, $first);
    }

    /**
     * Score a candidate for the "everything else" suggestion tier. A candidate
     * is only ELIGIBLE (returned score > 0) when part number OR vehicle agrees
     * — amount and tech name alone never make a candidate eligible, only add
     * to an already-eligible one's score for tiebreaking.
     */
    private function candidateScore(VendorDocumentLine $line, Job $job): int
    {
        $partMatch = $this->partNumberMatches($line, $job);
        $vehicleMatch = $this->vehicleAgrees($line, $job);

        if (! $partMatch && ! $vehicleMatch) {
            return 0;
        }

        $score = ($partMatch ? 3 : 0) + ($vehicleMatch ? 2 : 0);
        $score += $this->amountConfirms($line, $job) ? 1 : 0;
        $score += $this->techMatchesPo($line, $job) ? 1 : 0;

        return $score;
    }

    /** The unique top-scoring eligible candidate, or null when there's a tie (or nothing eligible). */
    private function bestSuggestion(VendorDocumentLine $line, Collection $candidates): ?Job
    {
        $scored = $candidates
            ->map(fn (Job $job) => ['job' => $job, 'score' => $this->candidateScore($line, $job)])
            ->filter(fn (array $c) => $c['score'] > 0);

        if ($scored->isEmpty()) {
            return null;
        }

        $max = $scored->max('score');
        $top = $scored->filter(fn (array $c) => $c['score'] === $max);

        return $top->count() === 1 ? $top->first()['job'] : null;
    }

    /**
     * Jobs within match_window_days of the line's confirmed/order date, keyed
     * off the job's own parts-ordered stamp (falling back to created_at for a
     * job whose part was never marked ordered — same fallback the ledger's
     * parts-purchase picker uses), excluding any job already linked to a
     * DIFFERENT billed line for this SAME part (a job can carry several
     * different parts, just not two lines of the identical part number).
     */
    private function candidateJobs(VendorDocumentLine $line): Collection
    {
        $days = (int) config('glass.vendors.mygrant.match_window_days', 7);
        $date = $line->confirmed_date ?: $line->order_date;
        if ($date === null) {
            return collect();
        }

        [$start, $end] = $this->windowAround($date, $days);

        return Job::query()
            ->whereNotNull('part_number')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('parts_ordered_at', [$start, $end])
                    ->orWhere(function ($inner) use ($start, $end) {
                        $inner->whereNull('parts_ordered_at')->whereBetween('created_at', [$start, $end]);
                    });
            })
            ->whereNotIn('id', $this->jobIdsAlreadyLinkedForPart($line))
            ->with('technician')
            ->get();
    }

    private function jobIdsAlreadyLinkedForPart(VendorDocumentLine $line): Collection
    {
        if ($line->part_number_base === null) {
            return collect();
        }

        return VendorDocumentLine::query()
            ->where('part_number_base', $line->part_number_base)
            ->where('id', '!=', $line->id)
            ->whereNotNull('job_id')
            ->pluck('job_id');
    }

    private function windowAround(string $date, int $days): array
    {
        $day = Carbon::parse($date, config('glass.timezone'));

        return [
            $day->copy()->subDays($days)->startOfDay()->utc(),
            $day->copy()->addDays($days)->endOfDay()->utc(),
        ];
    }

    // ---- Persistence ----

    private function linkMatched(VendorDocumentLine $line, Job $job, ?Employee $actor = null, string $source = 'auto'): void
    {
        $variance = $this->applyCostAndVariance($line, $job);

        $line->update([
            'job_id' => $job->id,
            'suggested_job_id' => null,
            'match_status' => VendorDocumentLine::MATCH_MATCHED,
            'match_source' => $source,
            'matched_at' => now(),
            'matched_by' => $actor?->id,
            'variance_cents' => $variance,
            'no_job_reason' => null,
        ]);
    }

    private function linkSuggested(VendorDocumentLine $line, Job $job): void
    {
        $line->update([
            'suggested_job_id' => $job->id,
            'match_status' => VendorDocumentLine::MATCH_SUGGESTED,
            'match_source' => 'auto',
        ]);
    }

    /**
     * Never overwrites a part cost a human typed — the typed cost is
     * authoritative, the invoice only confirms it. A genuinely blank
     * (null or 0) cost IS filled from the invoice (that's not an overwrite),
     * activity-logged so it's clearly attributable to the vendor feed rather
     * than a person, and returns variance 0 (nothing to flag — it just agreed
     * with itself). Otherwise returns the line total minus the job's cost, so
     * ANY non-zero difference is flagged (no tolerance, per the owner).
     */
    private function applyCostAndVariance(VendorDocumentLine $line, Job $job): int
    {
        $lineTotal = (int) $line->line_total_cents;
        $existing = (int) ($job->part_cost_cents ?? 0);

        if ($existing === 0) {
            $job->part_cost_cents = $lineTotal;
            $job->save();

            activity()->performedOn($job)
                ->event('vendor_part_cost_filled')
                ->log('Part cost filled from Mygrant invoice line '.$line->order_line.' ('.Money::format($lineTotal).')');

            return 0;
        }

        return $lineTotal - $existing;
    }
}
