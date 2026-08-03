<?php

namespace App\Console\Commands;

use App\Jobs\SendSmsJob;
use App\Models\ScheduledMessage;
use Illuminate\Console\Command;

/**
 * Scheduled every minute. Dispatches a SendSmsJob for each due outbox row.
 * Dispatch is afterCommit and the job itself is idempotent (compare-and-set
 * lock), so dispatching the same row twice still results in a single send.
 */
class ProcessDueMessages extends Command
{
    protected $signature = 'messages:process-due {--limit=500 : Max rows per run}';

    protected $description = 'Dispatch send jobs for due, queued outbox messages';

    public function handle(): int
    {
        $count = 0;

        ScheduledMessage::query()
            ->due()
            ->orderBy('scheduled_for')
            ->limit((int) $this->option('limit'))
            ->pluck('id')
            ->each(function (int $id) use (&$count) {
                SendSmsJob::dispatch($id)->afterCommit();
                $count++;
            });

        $this->info("Dispatched {$count} message(s).");

        return self::SUCCESS;
    }
}
