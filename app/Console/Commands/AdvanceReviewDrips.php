<?php

namespace App\Console\Commands;

use App\Services\Reviews\ReviewDripService;
use Illuminate\Console\Command;

/**
 * Scheduled every 5 minutes. First falls through any awaiting_call row past
 * its 7pm deadline (skipped or simply never logged) into the regular flow, so
 * that happens within minutes of the deadline — then pushes each due drip one
 * step forward (text 2 the day after text 1, the final text a week after text
 * 3, plus retries of any step that was blocked when first attempted). Sends
 * are idempotent per job+template, so overlapping runs can't double-text.
 */
class AdvanceReviewDrips extends Command
{
    protected $signature = 'reviews:advance';

    protected $description = 'Fall through overdue first calls, then send due review-drip texts';

    public function handle(ReviewDripService $drips): int
    {
        $fellThrough = $drips->autoStartOverdueFirstCalls();
        $count = $drips->advanceDue();

        $this->info("Auto-fell-through {$fellThrough} overdue first call(s). Advanced {$count} review drip(s).");

        return self::SUCCESS;
    }
}
