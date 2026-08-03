<?php

namespace App\Services\Messaging;

use App\Enums\TemplateKey;
use App\Models\Job;
use App\Models\ScheduledMessage;
use App\Support\Timezone;

/**
 * Computes night-before (7pm) and morning-of (8am) reminder send times in the
 * display timezone, then stores them as UTC. Applies the spec's guards:
 *  - same-day appointments get NO reminders
 *  - next-day appointments skip the night-before (morning-of only)
 *  - never schedule in the past or after the appointment
 *  - never schedule reminders once work is completed
 */
class ReminderScheduler
{
    public function __construct(private OutboxService $outbox, private SettingsRepository $settings) {}

    /**
     * (Re)create the reminder rows for a job. Cancels existing unsent reminders
     * first so a reschedule produces a clean, correct set.
     *
     * @return array<int, ScheduledMessage>
     */
    public function scheduleForJob(Job $job, ?int $createdBy = null): array
    {
        $this->outbox->cancelUnsentForJob($job, ['reminder'], 'rescheduled');

        if ($job->scheduled_at === null || $job->isWorkComplete()) {
            return [];
        }

        $now = Timezone::now();
        $localAppt = Timezone::toDisplay($job->scheduled_at);

        // Whole calendar days from today to the appointment, in the display tz:
        // 0 = same day, 1 = next day, 2+ = further out.
        $daysUntil = (int) round(
            $now->copy()->startOfDay()->diffInDays($localAppt->copy()->startOfDay(), false)
        );

        // Same-day appointments get no reminders at all.
        if ($daysUntil <= 0) {
            return [];
        }

        $nightBeforeAt = $this->settings->reminderNightBeforeAt();
        $morningOfAt = $this->settings->reminderMorningOfAt();

        $candidates = [];
        // Night-before only when the appointment is 2+ days out — next-day jobs
        // skip it (the customer was just booked; only the morning-of is useful).
        if ($daysUntil >= 2) {
            $candidates[] = [TemplateKey::ReminderNightBefore, Timezone::localDateTimeToUtc($localAppt->copy()->subDay(), $nightBeforeAt)];
        }
        // Morning-of for any future-day appointment (tomorrow or later).
        $candidates[] = [TemplateKey::ReminderMorningOf, Timezone::localDateTimeToUtc($localAppt->copy(), $morningOfAt)];

        $created = [];
        foreach ($candidates as [$key, $sendAtUtc]) {
            // Guard: never schedule in the past / skip if the window has passed.
            if ($sendAtUtc->lessThanOrEqualTo($now->utc())) {
                continue;
            }
            // Guard: never after the appointment itself.
            if ($sendAtUtc->greaterThan($job->scheduled_at)) {
                continue;
            }

            $msg = $this->outbox->queueForJob($job, $key, $sendAtUtc, $createdBy);
            if ($msg) {
                $created[] = $msg;
            }
        }

        return $created;
    }
}
