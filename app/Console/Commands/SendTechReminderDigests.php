<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Messaging\SettingsRepository;
use App\Services\Messaging\TechReminderScheduler;
use App\Support\Timezone;
use Illuminate\Console\Command;

/**
 * Self-gating tech-digest sender. Scheduled every 15 minutes; queues the
 * night-before digest once the local clock passes the configured night-before
 * time, and the morning-of digest once it passes the morning-of time. The
 * per-tech/phase/day idempotency key makes the repeated ticks no-ops after the
 * first successful queue, so the admin-configured times are honored without a
 * dedicated cron entry per time.
 */
class SendTechReminderDigests extends Command
{
    protected $signature = 'reminders:tech-digests {--phase= : force a phase (night_before|morning_of), ignoring the clock gate}';

    protected $description = 'Send technicians a digest of their jobs the night before and morning of.';

    public function handle(TechReminderScheduler $scheduler, SettingsRepository $settings): int
    {
        if (Setting::get('tech_reminders_enabled', '1') === '0') {
            $this->info('Tech reminders are disabled.');

            return self::SUCCESS;
        }

        $now = Timezone::now();

        if ($forced = $this->option('phase')) {
            $count = count($scheduler->run($forced, $now));
            $this->info("Queued {$count} {$forced} tech digest(s).");

            return self::SUCCESS;
        }

        $nowHm = $now->format('H:i');

        if ($nowHm >= $settings->reminderNightBeforeAt()) {
            $count = count($scheduler->run('night_before', $now));
            $this->info("Queued {$count} night-before tech digest(s).");
        }

        if ($nowHm >= $settings->reminderMorningOfAt()) {
            $count = count($scheduler->run('morning_of', $now));
            $this->info("Queued {$count} morning-of tech digest(s).");
        }

        return self::SUCCESS;
    }
}
