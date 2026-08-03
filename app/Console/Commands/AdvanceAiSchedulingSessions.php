<?php

namespace App\Console\Commands;

use App\Services\AiScheduling\AiSchedulingService;
use Illuminate\Console\Command;

/**
 * Moves live "Schedule w/ AI" sessions forward on the clock. Technician
 * replies arrive push-style from the inbound SMS webhook, so this sweep only
 * covers what time itself changes: a technician who never answered inside
 * their response window, a session held overnight outside contact hours
 * (AiSchedulingService::outreachAllowedNow), and a session whose requested
 * day has gone by.
 *
 * A no-op when nothing is running, so the every-two-minutes cadence costs
 * one query on a quiet day.
 */
class AdvanceAiSchedulingSessions extends Command
{
    protected $signature = 'ai:advance-sessions';

    protected $description = 'Advance live AI scheduling sessions (response timeouts, parked sessions, expiry).';

    public function handle(AiSchedulingService $service): int
    {
        $result = $service->sweep();

        $this->info(
            "AI scheduling sweep: {$result['advanced']} advanced, {$result['expired']} expired, "
            ."{$result['warehouse_asks_closed']} warehouse asks closed."
        );

        return self::SUCCESS;
    }
}
