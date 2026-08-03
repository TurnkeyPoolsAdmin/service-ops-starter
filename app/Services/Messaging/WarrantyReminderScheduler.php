<?php

namespace App\Services\Messaging;

use App\Enums\TemplateKey;
use App\Models\Employee;
use App\Models\ScheduledMessage;
use App\Models\WarrantyClaim;
use App\Support\Timezone;

/**
 * Reminders for a warranty return visit — the same night-before (7pm) and
 * morning-of (8am) cadence as a normal appointment, but keyed off the CLAIM's
 * visit time (not the original job's), with their own copy and a customer AND a
 * technician variant. Mirrors ReminderScheduler's guards:
 *   - same-day visits get no reminders
 *   - next-day visits skip the night-before (morning-of only)
 *   - never schedule in the past or after the visit itself
 *
 * The visit's date/time is baked into each row as the {warranty_date_time}
 * extra var (in the recipient's language), so the reminders read correctly even
 * though the original job carries a different, older appointment time.
 */
class WarrantyReminderScheduler
{
    /** Templates this scheduler owns — cancelled wholesale on a reschedule. */
    public const TEMPLATES = [
        TemplateKey::WarrantyReminderNightBefore,
        TemplateKey::WarrantyReminderMorningOf,
        TemplateKey::TechWarrantyReminderNightBefore,
        TemplateKey::TechWarrantyReminderMorningOf,
    ];

    public function __construct(private OutboxService $outbox, private SettingsRepository $settings) {}

    /**
     * (Re)create the reminder rows for a claim's scheduled visit. Cancels any
     * existing unsent warranty reminders on the original job first so a
     * reschedule produces a clean set.
     *
     * @return array<int, ScheduledMessage>
     */
    public function scheduleForClaim(WarrantyClaim $claim, ?int $createdBy = null): array
    {
        $job = $claim->job;

        $this->outbox->cancelUnsentForJobTemplates($job, self::TEMPLATES, 'warranty visit rescheduled');

        if ($claim->scheduled_at === null || ! $claim->isLive()) {
            return [];
        }

        $now = Timezone::now();
        $localVisit = Timezone::toDisplay($claim->scheduled_at);

        // Whole calendar days from today to the visit (display tz): 0 = today.
        $daysUntil = (int) round(
            $now->copy()->startOfDay()->diffInDays($localVisit->copy()->startOfDay(), false)
        );

        if ($daysUntil <= 0) {
            return [];
        }

        $nightBeforeAt = $this->settings->reminderNightBeforeAt();
        $morningOfAt = $this->settings->reminderMorningOfAt();

        // [customerKey, techKey, sendAtUtc] per phase.
        $phases = [];
        if ($daysUntil >= 2) {
            $phases[] = [
                'night',
                TemplateKey::WarrantyReminderNightBefore,
                TemplateKey::TechWarrantyReminderNightBefore,
                Timezone::localDateTimeToUtc($localVisit->copy()->subDay(), $nightBeforeAt),
            ];
        }
        $phases[] = [
            'morning',
            TemplateKey::WarrantyReminderMorningOf,
            TemplateKey::TechWarrantyReminderMorningOf,
            Timezone::localDateTimeToUtc($localVisit->copy(), $morningOfAt),
        ];

        $created = [];
        $dateKey = $localVisit->format('Y-m-d');
        $tech = $claim->technician_id ? Employee::find($claim->technician_id) : null;

        foreach ($phases as [$phase, $customerKey, $techKey, $sendAtUtc]) {
            // Guards: never in the past, never after the visit itself.
            if ($sendAtUtc->lessThanOrEqualTo($now->utc()) || $sendAtUtc->greaterThan($claim->scheduled_at)) {
                continue;
            }

            // Customer reminder — visit phrase in the customer's language.
            $customerMsg = $this->outbox->queueForJob(
                $job,
                $customerKey,
                $sendAtUtc,
                $createdBy,
                idempotencyKey: "warranty:{$claim->id}:remind:{$phase}:{$dateKey}",
                extraVars: ['warranty_date_time' => $claim->visitPhrase($job->customer?->preferredLanguage())],
            );
            if ($customerMsg) {
                $created[] = $customerMsg;
            }

            // Tech reminder — visit phrase in the tech's language.
            if ($tech && $tech->active) {
                $techMsg = $this->outbox->queueJobMessageForTech(
                    $job,
                    $tech,
                    $techKey,
                    $createdBy,
                    $sendAtUtc,
                    ['warranty_date_time' => $claim->visitPhrase($tech->preferredLanguage())],
                    "warranty:{$claim->id}:tech-remind:{$phase}:{$dateKey}",
                );
                if ($techMsg) {
                    $created[] = $techMsg;
                }
            }
        }

        return $created;
    }
}
