<?php

use App\Models\Job;
use App\Models\Warehouse;
use Illuminate\Database\Migrations\Migration;

/**
 * O'Reilly Auto Parts has no Warehouse row at all — confirmed via a direct
 * prod tinker query (owner, 2026-07-28). Two jobs reference it only via
 * free-text warehouse_other, inconsistently typed ("Part at oreillys LA" /
 * "O Reilly Auto Parts"), which is exactly why it never showed up in the
 * Bank Transactions Match modal's "Parts purchase" warehouse dropdown — that
 * picker only lists real Warehouse rows (BankTransactionsTable::
 * warehouseSelectOptions). Same situation Pacific BMW / Pilkington were in
 * before THEY got a row — neither of those has a migration or seeder either;
 * both were added by hand through Admin -> Warehouses. This one ships as a
 * migration instead, purely because "push to master" is already how
 * everything here deploys, and a migration lands the new row + the two-job
 * backfill together, atomically, with an audit trail (a hand click wouldn't).
 *
 * supplier / supplier_code are left null — O'Reilly's isn't a supplier this
 * app has automated invoice reconciliation for (unlike Mygrant/IGC), same as
 * Pacific BMW / Pilkington.
 *
 * warehouse_id / warehouse_other are mutually exclusive by convention
 * (JobForm::warehouseFields()'s warehouse_other field dehydrates to null
 * whenever warehouse_id holds a real value — every normal job save already
 * clears it). This backfill follows that same convention rather than leaving
 * both populated.
 */
return new class extends Migration
{
    private const JOBS = [
        190 => 'LA-2026-0072',
        227 => 'LA-2026-0104',
    ];

    public function up(): void
    {
        $warehouse = Warehouse::updateOrCreate(
            ['name' => 'O Reilly Auto Parts'],
            ['active' => true, 'supplier' => null, 'supplier_code' => null],
        );

        foreach (self::JOBS as $jobId => $expectedJobNumber) {
            $job = Job::find($jobId);

            if ($job === null) {
                continue; // no longer exists — nothing to backfill
            }

            // Refuse to touch a row that doesn't match what was actually
            // looked up in prod — safer to fail the deploy than silently
            // relink the wrong job.
            if ($job->job_number !== $expectedJobNumber) {
                throw new \RuntimeException(
                    "Job {$jobId} expected job_number {$expectedJobNumber} but found "
                    .($job->job_number ?? 'null').' — refusing to backfill a possibly-wrong row.'
                );
            }

            if ($job->warehouse_id !== null) {
                continue; // already has a real warehouse linked — don't clobber it
            }

            $job->update(['warehouse_id' => $warehouse->id, 'warehouse_other' => null]);
        }
    }

    public function down(): void
    {
        // Deliberately no-op: reversing would need to remember each job's
        // original warehouse_other string to restore it, and re-orphaning two
        // real jobs' warehouse link on a rollback is riskier than leaving this
        // forward-only — same call as this codebase's other one-off data
        // migrations (e.g. 2026_07_22_120000_baseline_spanish_translation_reviews).
    }
};
