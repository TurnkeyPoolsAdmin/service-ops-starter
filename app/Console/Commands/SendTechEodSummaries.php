<?php

namespace App\Console\Commands;

use App\Services\Messaging\TechEodSummaryScheduler;
use Illuminate\Console\Command;

/**
 * Texts each opted-in technician (employees.eod_summary_texts_enabled) a
 * nightly recap of the day's completed work and pay. Scheduled once daily
 * (routes/console.php, glass.tech_eod_summary_at). No enable/disable Setting
 * gate — the per-employee toggle IS the gate, and the scheduler itself sends
 * nothing for a tech who completed nothing that day.
 */
class SendTechEodSummaries extends Command
{
    protected $signature = 'tech:eod-summaries';

    protected $description = 'Text opted-in technicians their end-of-day pay summary.';

    public function handle(TechEodSummaryScheduler $scheduler): int
    {
        $count = count($scheduler->run());

        $this->info("Queued {$count} tech end-of-day summary text(s).");

        return self::SUCCESS;
    }
}
