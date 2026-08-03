<?php

namespace App\Services\Warranty;

use App\Enums\IssueType;
use App\Enums\PaymentMethod;
use App\Enums\TemplateKey;
use App\Filament\Resources\Jobs\JobResource;
use App\Jobs\SendSmsJob;
use App\Models\Employee;
use App\Models\Issue;
use App\Models\Job;
use App\Models\ScheduledMessage;
use App\Models\Setting;
use App\Models\TechSettlement;
use App\Models\WarrantyClaim;
use App\Services\Messaging\OutboxService;
use App\Services\Messaging\WarrantyReminderScheduler;
use App\Services\Telegram\TelegramNotifier;
use App\Support\Money;
use App\Support\Timezone;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Warranty return visits, start to finish:
 *
 *   open (customer reported an issue on a completed job)
 *     → scheduled (tech return visit booked — one heads-up text + reminders;
 *                  OPTIONAL extra tech pay; issues if a different tech / extra pay)
 *     → resolved (issue fixed — "all set" text, no review drip)   — or canceled.
 *
 * Unlike a normal job the visit is unpaid by default. When it does carry extra
 * tech pay, that money flows through the same End-of-Day + bank-reconciliation
 * machinery as a payout (a warranty_payout TechSettlement); the original job's
 * own payment and payout always stand untouched.
 */
class WarrantyService
{
    public function __construct(
        private OutboxService $outbox,
        private WarrantyReminderScheduler $reminders,
        private TelegramNotifier $telegram,
    ) {}

    /**
     * Flag a completed job as needing a warranty fix. Guards: the work must be
     * done (completed or work-completed job), and a job can only have one live
     * claim at a time. Captures the ORIGINAL tech so scheduling a DIFFERENT one
     * later raises an Issue, and posts a heads-up to Telegram (the recurring
     * unscheduled-warranty alert keeps nagging until it's booked).
     */
    public function openForJob(Job $job, string $issue, Employee $actor): WarrantyClaim
    {
        if (! $job->isWorkComplete()) {
            throw new \RuntimeException('Warranty claims are for jobs whose work is complete.');
        }
        if ($job->warrantyClaims()->live()->exists()) {
            throw new \RuntimeException('This job already has an open warranty claim.');
        }

        $claim = DB::transaction(function () use ($job, $issue, $actor) {
            $claim = WarrantyClaim::create([
                'job_id' => $job->id,
                'customer_id' => $job->customer_id,
                'entity_id' => $job->entity_id,
                'status' => WarrantyClaim::STATUS_OPEN,
                'issue' => $issue,
                'technician_id' => $job->technician_id,
                'original_technician_id' => $job->technician_id,
                'created_by' => $actor->id,
            ]);

            activity()->performedOn($job)->causedBy($actor)
                ->event('warranty_opened')
                ->log('Warranty claim opened: '.str($issue)->limit(120));

            return $claim;
        });

        $this->postOpenedAlert($claim->fresh(['job']));

        return $claim;
    }

    /**
     * Book (or move) the return visit. Sends the one generic heads-up text (again
     * only if the time actually changed), (re)schedules the customer + tech
     * reminders, and — when someone other than the original tech is sent, or the
     * visit is given extra pay — raises an Issue for the super-admin queue.
     *
     * $techPayCents is OPTIONAL extra pay for the visit (null/0 = unpaid). When
     * set, it becomes owed on completion and settles as a warranty_payout.
     *
     * Returns the queued heads-up text, or null when it was blocked (opted out /
     * paused, template inactive).
     */
    public function schedule(
        WarrantyClaim $claim,
        CarbonInterface $scheduledAtUtc,
        Employee $actor,
        ?int $technicianId = null,
        bool $exactTime = false,
        ?int $windowHours = null,
        ?int $techPayCents = null,
    ): ?ScheduledMessage {
        if (! $claim->isLive()) {
            throw new \RuntimeException('Only open warranty claims can be scheduled.');
        }

        return DB::transaction(function () use ($claim, $scheduledAtUtc, $actor, $technicianId, $exactTime, $windowHours, $techPayCents) {
            $claim->scheduled_at = $scheduledAtUtc->copy()->utc();
            $claim->scheduled_exact_time = $exactTime;
            $claim->scheduled_window_hours = $exactTime ? null : $windowHours;
            if ($technicianId !== null) {
                $claim->technician_id = $technicianId;
            }
            $claim->tech_pay_cents = ($techPayCents !== null && $techPayCents > 0) ? $techPayCents : null;
            $claim->scheduled_by = $actor->id;
            $claim->status = WarrantyClaim::STATUS_SCHEDULED;
            // Re-arm the overdue watchdog for the (new) visit time.
            $claim->overdue_completion_alerted_at = null;
            $claim->save();

            activity()->performedOn($claim->job)->causedBy($actor)
                ->event('warranty_scheduled')
                ->log('Warranty visit scheduled for '.$claim->visitPhrase());

            $this->raiseSchedulingIssues($claim, $actor);

            $message = $this->outbox->queueForJob(
                $claim->job,
                TemplateKey::WarrantyVisitScheduled,
                null,
                $actor->id,
                idempotencyKey: 'warranty:'.$claim->id
                    .':tpl:'.TemplateKey::WarrantyVisitScheduled->value
                    .':for:'.$claim->scheduled_at->toIso8601String(),
                // The customer reads this date, so phrase it in their language.
                extraVars: [
                    'warranty_date_time' => $claim->visitPhrase(
                        $claim->job->customer?->preferredLanguage(),
                    ),
                ],
            );

            if ($message) {
                SendSmsJob::dispatch($message->id)->afterCommit();
            }

            // Night-before / morning-of reminders for the customer AND the tech.
            $this->reminders->scheduleForClaim($claim, $actor->id);

            return $message;
        });
    }

    /**
     * A different tech than the original installer, and/or extra pay, each raise
     * an Issue keyed on the original JOB (so the Issues page's job link works and
     * repeated reschedules never duplicate the row).
     */
    private function raiseSchedulingIssues(WarrantyClaim $claim, Employee $actor): void
    {
        $job = $claim->job;
        $base = [
            'job_number' => $job->job_number,
            'customer_name' => $job->customer_name_snapshot,
            'warranty_claim_id' => $claim->id,
            'issue' => $claim->issue,
        ];

        if ($claim->techChangedFromOriginal()) {
            Issue::raise(IssueType::WarrantyTechChanged, $job, [
                ...$base,
                'original_tech' => $claim->originalTechnician?->name,
                'assigned_tech' => $claim->technician?->name,
            ], $actor->id);
        }

        if ($claim->hasTechPay()) {
            Issue::raise(IssueType::WarrantyTechPay, $job, [
                ...$base,
                'assigned_tech' => $claim->technician?->name,
                'tech_pay_cents' => (int) $claim->tech_pay_cents,
            ], $actor->id);
        }
    }

    /**
     * The issue is fixed — close the claim out, send the customer one plain
     * "all set" text (NO review drip), and record the optional "how did it go"
     * sentiment. Extra tech pay, if any, is now owed and shows on End-of-Day
     * until settled (see markTechPaid).
     */
    public function resolve(WarrantyClaim $claim, ?string $resolutionNotes, Employee $actor, ?string $sentiment = null): WarrantyClaim
    {
        if (! $claim->isLive()) {
            return $claim;
        }

        $claim->status = WarrantyClaim::STATUS_RESOLVED;
        $claim->resolved_at = now();
        $claim->resolved_by = $actor->id;
        $claim->resolution_notes = $resolutionNotes;
        if ($sentiment !== null) {
            $claim->sentiment = $sentiment;
        }
        $claim->save();

        activity()->performedOn($claim->job)->causedBy($actor)
            ->event('warranty_resolved')->log('Warranty claim resolved');

        // Any unsent visit reminders are moot now.
        $this->outbox->cancelUnsentForJobTemplates(
            $claim->job, WarrantyReminderScheduler::TEMPLATES, 'warranty visit completed',
        );

        // "All set" wrap-up — plain, no review-request drip.
        $message = $this->outbox->queueForJob(
            $claim->job,
            TemplateKey::WarrantyCompleted,
            null,
            $actor->id,
            idempotencyKey: 'warranty:'.$claim->id.':tpl:'.TemplateKey::WarrantyCompleted->value,
        );
        if ($message) {
            SendSmsJob::dispatch($message->id)->afterCommit();
        }

        return $claim;
    }

    /** Opened by mistake / customer withdrew — close without a fix. */
    public function cancel(WarrantyClaim $claim, ?string $reason, Employee $actor): WarrantyClaim
    {
        if (! $claim->isLive()) {
            return $claim;
        }

        $claim->status = WarrantyClaim::STATUS_CANCELED;
        $claim->canceled_at = now();
        $claim->cancel_reason = $reason;
        $claim->save();

        activity()->performedOn($claim->job)->causedBy($actor)
            ->event('warranty_canceled')->log('Warranty claim canceled');

        $this->outbox->cancelUnsentForJobTemplates(
            $claim->job, WarrantyReminderScheduler::TEMPLATES, 'warranty visit canceled',
        );

        return $claim;
    }

    /**
     * Settle a completed visit's extra tech pay. Mirrors an End-of-Day payout:
     * writes a warranty_payout TechSettlement (bank-matchable exactly like an
     * eod_payout via BankMatcher::findTechPayout) dated to the completion day,
     * and stamps the claim tech-paid. Idempotent — a no-op once tech_paid_at is
     * set. Returns the settlement, or null when there's nothing to pay.
     */
    public function markTechPaid(
        WarrantyClaim $claim,
        ?PaymentMethod $method,
        ?string $reference,
        Employee $actor,
    ): ?TechSettlement {
        if (! $claim->isWarrantyPayOwed()) {
            return null;
        }

        return DB::transaction(function () use ($claim, $method, $reference, $actor) {
            $forDate = Timezone::toDisplay($claim->resolved_at ?? now())->format('Y-m-d');
            $amount = (int) $claim->tech_pay_cents;

            $settlement = TechSettlement::create([
                'technician_id' => $claim->technician_id,
                'warranty_claim_id' => $claim->id,
                'type' => 'warranty_payout',
                'for_date' => $forDate,
                'amount_cents' => $amount,
                'direction' => 'paid_to_tech',
                'tech_pay_owed_cents' => $amount,
                'cash_collected_cents' => 0,
                'balance_before_cents' => 0,
                'balance_after_cents' => 0,
                'job_count' => 0,
                'settled_job_ids' => [],
                'method' => $method?->value,
                'reference' => $reference,
                'note' => 'Warranty visit — '.($claim->job?->job_number ?? '').' — '.str($claim->issue)->limit(60),
                'settled_at' => now(),
                'recorded_by' => $actor->id,
            ]);

            $claim->tech_paid_at = now();
            $claim->tech_paid_by = $actor->id;
            $claim->tech_paid_method = $method?->value;
            $claim->tech_paid_reference = $reference;
            $claim->save();

            activity()->performedOn($claim->job)->causedBy($actor)
                ->event('warranty_tech_paid')
                ->log('Warranty visit tech pay of '.Money::format($amount).' settled');

            return $settlement;
        });
    }

    /**
     * Immediate "a warranty was just filed" heads-up to the group chat. Best
     * effort — self-gates on the shared telegram_alerts_enabled toggle + bot
     * config, and never blocks opening the claim if it fails.
     */
    private function postOpenedAlert(WarrantyClaim $claim): void
    {
        if (Setting::get('telegram_alerts_enabled', '1') === '0' || ! $this->telegram->configured()) {
            return;
        }

        $job = $claim->job;
        $name = e($job?->customer_name_snapshot ?: 'Unknown');
        $link = $job ? '<a href="'.JobResource::getUrl('view', ['record' => $job]).'">'.$name.'</a>' : $name;
        $loc = e($claim->entity?->code ?? '—');
        $issue = e(str($claim->issue)->limit(140));

        $this->telegram->send(trim(
            "🛡️ <b>Service Ops — Warranty filed</b>\n\n"
            ."• {$link} — {$loc}\n"
            ."Issue: {$issue}\n\n"
            .'Needs a return visit scheduled.'
        ));
    }
}
