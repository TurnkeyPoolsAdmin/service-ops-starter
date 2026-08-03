<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Job;
use App\Models\TechSettlement;
use App\Services\JobLifecycle;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * One-off fixup for a settlement that locked in before something changed —
 * e.g. a tech-fronted part got flagged "tech paid" after End-of-Day had
 * already run for that tech/day. Undoes the latest eod_payout settlement for
 * the given technician + local date and immediately re-settles, so the day's
 * balance reflects whatever is on the jobs now. Backfills settled_job_ids on
 * legacy settlements (created before that column existed) by matching jobs
 * whose tech_paid_at equals the settlement's settled_at — same-second, since
 * markTechPaid stamps every job in a settlement within one request.
 *
 * Runs the same JobLifecycle code the End-of-Day Summary UI's Undo/Mark
 * confirmed buttons use, so this is not a raw data patch — it's the app's own
 * settlement logic, invoked once for a specific tech/day.
 */
class ResettleTechDay extends Command
{
    protected $signature = 'settlements:resettle {technician_id} {date} {--actor=}';

    protected $description = "Undo a technician's End-of-Day settlement for one date and re-settle it";

    public function handle(JobLifecycle $lifecycle): int
    {
        $tech = Employee::find((int) $this->argument('technician_id'));

        if ($tech === null) {
            $this->error('No such employee.');

            return self::FAILURE;
        }

        $date = $this->argument('date');

        $actorId = $this->option('actor') ?: $this->argument('technician_id');
        $actor = Employee::find((int) $actorId) ?? $tech;

        $settlement = TechSettlement::where('technician_id', $tech->id)
            ->where('for_date', $date)
            ->where('type', 'eod_payout')
            ->whereNull('undone_at')
            ->latest('id')
            ->first();

        if ($settlement === null) {
            $this->error("No open eod_payout settlement found for {$tech->name} on {$date}.");

            return self::FAILURE;
        }

        if (empty($settlement->settled_job_ids)) {
            $jobIds = Job::where('technician_id', $tech->id)
                ->where('tech_paid_at', $settlement->settled_at)
                ->pluck('id')
                ->all();

            if (count($jobIds) !== $settlement->job_count) {
                $this->error(sprintf(
                    'Could not confidently reconstruct the job set for settlement #%d (expected %d jobs, matched %d by tech_paid_at). Aborting — fix settled_job_ids manually before retrying.',
                    $settlement->id,
                    $settlement->job_count,
                    count($jobIds),
                ));

                return self::FAILURE;
            }

            $settlement->settled_job_ids = $jobIds;
            $settlement->save();
            $this->info("Backfilled settled_job_ids on settlement #{$settlement->id}: [".implode(',', $jobIds).']');
        }

        $this->info("Balance before undo: {$this->money($tech->cash_balance_cents)}");

        $lifecycle->undoSettleTechDay($settlement, $actor);

        $tech->refresh();
        $this->info("Balance after undo: {$this->money($tech->cash_balance_cents)}");

        $resettled = $lifecycle->settleTechDay($tech, $date, null, null, 'Re-settled via settlements:resettle to pick up a correction', $actor);

        if ($resettled === null) {
            $this->warn('Nothing came back as open to re-settle — check the jobs manually.');

            return self::FAILURE;
        }

        $tech->refresh();
        $this->info("Re-settled. Owed: {$this->money($resettled->tech_pay_owed_cents)}, payout: {$this->money($resettled->amount_cents)}, balance after: {$this->money($tech->cash_balance_cents)}");

        return self::SUCCESS;
    }

    private function money(int $cents): string
    {
        return Money::format($cents);
    }
}
