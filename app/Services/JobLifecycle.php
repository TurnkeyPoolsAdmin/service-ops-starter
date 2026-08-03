<?php

namespace App\Services;

use App\Enums\IssueType;
use App\Enums\JobStatus;
use App\Enums\Language;
use App\Enums\MessageType;
use App\Enums\OptOutSource;
use App\Enums\PaymentMethod;
use App\Enums\SmsConsentStatus;
use App\Enums\TemplateKey;
use App\Enums\TimePreference;
use App\Jobs\SendSmsJob;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Issue;
use App\Models\Job;
use App\Models\JobNote;
use App\Models\JobPayment;
use App\Models\ScheduledMessage;
use App\Models\TechSettlement;
use App\Services\Calibration\CalibrationService;
use App\Services\Messaging\OutboxService;
use App\Services\Messaging\ReminderScheduler;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Reviews\ReviewDripService;
use App\Services\Stripe\StripeClient;
use App\Support\GlassPosition;
use App\Support\Money;
use App\Support\PartNumberGlass;
use App\Support\PhoneNumber;
use App\Support\Timezone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Central state machine for jobs. Every transition and its side effects live
 * here so they fire reliably and exactly once. Money/state actions record the
 * acting employee and emit activity-log events for the job timeline.
 */
class JobLifecycle
{
    public function __construct(
        private OutboxService $outbox,
        private ReminderScheduler $reminders,
    ) {}

    /**
     * Create a customer (findOrCreate by phone) + job from confirmed intake data.
     *
     * $data keys: entity_id, phone_e164, first_name, last_name, email, city,
     * service_address, contact_pref, preferred_language, vehicle_*, vin, glass_position, vehicle_raw,
     * part_number, parts_json, part_cost_cents, part_available, labor_only, quote_amount_cents,
     * is_insurance, insurance_carrier, needs_calibration, is_multi_day, booking_salesperson_id,
     * booking_salesperson_name, booking_salesperson_phone, external_source, external_ref,
     * requested_daytime_raw, requested_day, requested_time,
     * requested_time_preference, requested_after_time,
     * requested_custom, scheduled_at (optional, rare),
     * intake_raw_text, parsed_payload_json, parse_status, parse_warnings_json.
     */
    public function createFromIntake(array $data, Employee $actor): Job
    {
        return DB::transaction(function () use ($data, $actor) {
            $customer = $this->findOrCreateCustomer($data);
            $entity = Entity::findOrFail($data['entity_id']);

            $job = new Job;
            $job->fill([
                'entity_id' => $entity->id,
                'customer_id' => $customer->id,
                'customer_name_snapshot' => $customer->full_name,
                'customer_phone_snapshot' => $customer->phone_e164,
                'vehicle_year' => $data['vehicle_year'] ?? null,
                'vehicle_make' => $data['vehicle_make'] ?? null,
                'vehicle_model' => $data['vehicle_model'] ?? null,
                'vin' => $data['vin'] ?? null,
                'glass_position' => $data['glass_position'] ?? null,
                'vehicle_raw' => $data['vehicle_raw'] ?? null,
                'service_address' => $data['service_address'] ?? null,
                'city' => $data['city'] ?? null,
                'part_number' => $data['part_number'] ?? null,
                'parts_json' => $data['parts_json'] ?? null,
                'part_cost_cents' => $data['part_cost_cents'] ?? null,
                'part_available' => $data['part_available'] ?? null,
                // Customer brings their own glass — we only install it.
                'labor_only' => $data['labor_only'] ?? false,
                'quote_amount_cents' => $data['quote_amount_cents'] ?? null,
                'is_insurance' => $data['is_insurance'] ?? false,
                // Insurance always needs calibration - but only on a WINDSHIELD
                // (nothing else carries the ADAS camera). Manual flag still applies.
                'needs_calibration' => (bool) ((($data['is_insurance'] ?? false)
                    && GlassPosition::isWindshield($data['glass_position'] ?? null))
                    || ($data['needs_calibration'] ?? false)),
                'is_multi_day' => $data['is_multi_day'] ?? false,
                'insurance_carrier' => $data['insurance_carrier'] ?? null,
                'booking_salesperson_id' => $data['booking_salesperson_id'] ?? null,
                // Snapshot of a booking rep who has no `employees` row here —
                // the sales dashboard's own users. Null on manual intake, which
                // picks a real employee instead. See Job::bookingSalespersonName().
                'booking_salesperson_name' => $data['booking_salesperson_name'] ?? null,
                'booking_salesperson_phone' => $data['booking_salesperson_phone'] ?? null,
                // Upstream system + its id for this lead. The (source, ref)
                // unique index is what makes an auto-intake push idempotent.
                'external_source' => $data['external_source'] ?? null,
                'external_ref' => $data['external_ref'] ?? null,
                'requested_daytime_raw' => $data['requested_daytime_raw'] ?? null,
                'requested_day' => $data['requested_day'] ?? null,
                'requested_time' => $data['requested_time'] ?? null,
                'requested_time_preference' => $data['requested_time_preference'] ?? TimePreference::None->value,
                'requested_after_time' => $data['requested_after_time'] ?? null,
                'requested_custom' => $data['requested_custom'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'status' => JobStatus::PendingSchedule->value,
                'intake_raw_text' => $data['intake_raw_text'] ?? null,
                'notes' => $data['notes'] ?? null,
                'parsed_payload_json' => $data['parsed_payload_json'] ?? null,
                'parse_status' => $data['parse_status'] ?? 'clean',
                'parse_warnings_json' => $data['parse_warnings_json'] ?? null,
                'is_test' => $data['is_test'] ?? false,
                'created_by' => $actor->id,
            ]);
            $this->saveWithUniqueJobNumber($job, $entity);

            activity()->performedOn($job)->causedBy($actor)
                ->event('lead_created')->log('Lead created from intake');

            // The welcome is NOT auto-queued: the dispatcher picks the flow on
            // the SendWelcome screen right after intake (see sendWelcome()).

            return $job->refresh();
        });
    }

    /**
     * Queue the customer's welcome after intake. The dispatcher decides on the
     * SendWelcome screen whether the sales team already handed the customer off:
     *
     *  - Acknowledged: just our dispatch intro, from the Quo number.
     *  - Not acknowledged: a handoff note FROM the Podium sales thread first,
     *    then our dispatch intro from the Quo number 45s later, so the new
     *    number isn't a surprise.
     *
     * Messages are dispatched immediately (not left for the every-minute cron)
     * so the first text goes out promptly and the 45-second gap between the two
     * is actually honored.
     *
     * Idempotent: a job that already has a welcome-type row is left untouched
     * (guards against a double-click / refresh of the SendWelcome screen).
     */
    public function sendWelcome(Job $job, bool $salesAcknowledged, Employee $actor): void
    {
        if ($job->scheduledMessages()->where('message_type', MessageType::Welcome->value)->exists()) {
            return;
        }

        // Business-hours intro by default; after-hours / weekend variants fire
        // automatically based on the current local time.
        $introKey = TemplateKey::welcomeDispatchIntroFor(Timezone::now());

        $introDelay = (int) config('glass.send.welcome_intro_delay_seconds', 45);

        if ($salesAcknowledged) {
            $intro = $this->outbox->queueForJob($job, $introKey, null, $actor->id, provider: 'openphone');
            $this->dispatchNow($intro);
        } else {
            // Podium handoff goes out now...
            $handoff = $this->outbox->queueForJob($job, TemplateKey::WelcomePodiumHandoff, null, $actor->id, provider: 'podium');
            $this->dispatchNow($handoff);

            // ...then the Quo dispatch intro 45 seconds later.
            $intro = $this->outbox->queueForJob($job, $introKey, now()->addSeconds($introDelay), $actor->id, provider: 'openphone');
            $this->dispatchAfter($intro, $introDelay);
        }

        activity()->performedOn($job)->causedBy($actor)
            ->event('welcome_sent')
            ->log($salesAcknowledged
                ? 'Welcome sent — dispatch intro (handoff already acknowledged)'
                : 'Welcome sent — Podium handoff + dispatch intro (+45s)');
    }

    /** Dispatch a queued row immediately (no-op when queueing was blocked). */
    private function dispatchNow(?ScheduledMessage $message): void
    {
        if ($message) {
            SendSmsJob::dispatch($message->id)->afterCommit();
        }
    }

    /** Dispatch a queued row after a delay (the cron is a fallback via scheduled_for). */
    private function dispatchAfter(?ScheduledMessage $message, int $seconds): void
    {
        if ($message) {
            SendSmsJob::dispatch($message->id)->delay(now()->addSeconds($seconds))->afterCommit();
        }
    }

    /**
     * Dispatcher-initiated one-off text from the job view "Messaging" dropdown.
     * Queues a manual row and dispatches it immediately so it sends now instead
     * of waiting for the next messages:process-due tick. Returns the row, or
     * null when the send is blocked (opted out / can't render / no number).
     *
     * $scheduledForUtc holds the send until a later instant (see
     * followUpSendAtUtc) instead of firing now; the row still carries the time,
     * so the every-minute processor is the fallback if the delayed queue job is
     * lost.
     */
    public function sendManualMessage(
        Job $job,
        TemplateKey $key,
        Employee $actor,
        array $extraVars = [],
        ?CarbonInterface $scheduledForUtc = null,
    ): ?ScheduledMessage {
        $message = $this->outbox->queueManual(
            $job, $key, $actor->id, extraVars: $extraVars, scheduledForUtc: $scheduledForUtc,
        );

        if ($message === null) {
            return null;
        }

        $delay = $scheduledForUtc ? now()->diffInSeconds($scheduledForUtc, false) : 0;

        if ($delay > 0) {
            $this->dispatchAfter($message, (int) ceil($delay));
        } else {
            $this->dispatchNow($message);
        }

        activity()->performedOn($job)->causedBy($actor)
            ->event('manual_message_sent')
            ->log('Manual text sent: '.$key->label());

        return $message;
    }

    /**
     * The earliest instant a dispatcher-pressed FOLLOW-UP text may go out for
     * this job, or null when it can just send now.
     *
     * The welcome is a delayed pair (Podium handoff now, Quo dispatch intro
     * ~45s later), but the "Send scheduling text" button appears as soon as
     * those rows EXIST — not once they've sent. Firing the follow-up on click
     * would wedge it between the two, so the customer reads our scheduling
     * question before the intro that explains who is texting them. Instead we
     * hold it until the last pending welcome has gone out, plus a gap, so the
     * dispatcher still presses once and the order is guaranteed.
     */
    public function followUpSendAtUtc(Job $job): ?CarbonInterface
    {
        $welcomeAt = $job->pendingWelcomeSendAtUtc();

        if ($welcomeAt === null) {
            return null;
        }

        $sendAt = $welcomeAt->copy()->addSeconds((int) config('glass.send.follow_up_gap_seconds', 30));

        return $sendAt->isFuture() ? $sendAt : null;
    }

    /**
     * Text the selected technicians the availability request for a job, each in
     * their own language ("can you take this? what time? where should we order
     * the part?" — Spanish for most of the crew). Used
     * after-hours/Sundays/holidays to start the conversation before the office
     * calls back to confirm. Each tech gets one immediate, openphone-routed text;
     * idempotent per job+tech so re-running never double-texts. Returns the count
     * actually queued (a tech with no phone is skipped).
     *
     * @param  array<int, int|string>  $technicianIds
     */
    public function sendTechAvailabilityRequests(Job $job, array $technicianIds, Employee $actor): int
    {
        $techs = Employee::query()
            ->whereKey(array_filter(array_map('intval', $technicianIds)))
            ->get();

        $sent = 0;
        foreach ($techs as $tech) {
            $message = $this->outbox->queueJobMessageForTech($job, $tech, TemplateKey::TechJobAvailability, $actor->id);
            if ($message) {
                $this->dispatchNow($message);
                $sent++;
            }
        }

        if ($sent > 0) {
            activity()->performedOn($job)->causedBy($actor)
                ->event('tech_availability_requested')
                ->log("Tech availability request texted to {$sent} tech(s)");
        }

        return $sent;
    }

    public function findOrCreateCustomer(array $data): Customer
    {
        $phone = $data['phone_e164'] ?? PhoneNumber::toE164($data['phone_raw'] ?? null);
        abort_if(blank($phone), 422, 'A normalized phone number is required to create a customer.');

        // Include soft-deleted rows: the phone_e164 unique index also covers
        // trashed customers, so a plain firstOrNew would silently miss a deleted
        // customer and then fail to INSERT a duplicate. Restore (revive) instead.
        $customer = Customer::withTrashed()->firstOrNew(['phone_e164' => $phone]);
        if ($customer->trashed()) {
            $customer->restore();
        }

        // Fill blanks without clobbering existing known data.
        $customer->first_name ??= $data['first_name'] ?? null;
        $customer->last_name ??= $data['last_name'] ?? null;
        $customer->email ??= $data['email'] ?? null;
        $customer->city ??= $data['city'] ?? null;
        $customer->contact_pref ??= $data['contact_pref'] ?? null;

        // Intake only ever SETS a language (its "Spanish speaker" toggle passes
        // 'es' or null), so leaving the toggle off on a repeat customer's second
        // job can't silently reset them to English. Switching someone back is
        // done from the job view's language action.
        $languageChanged = false;
        if (filled($data['preferred_language'] ?? null)) {
            $languageChanged = $customer->preferredLanguage()->value !== $data['preferred_language'];
            $customer->preferred_language = $data['preferred_language'];
        }

        $customer->save();

        // A repeat customer we've only now learned speaks Spanish may already
        // have English texts sitting in the outbox from an earlier job; rewrite
        // them so the switch applies to everything still unsent, not just the
        // texts this new job will queue.
        if ($languageChanged && $customer->wasRecentlyCreated === false) {
            $this->outbox->rerenderUnsentForCustomer($customer);
        }

        return $customer;
    }

    /**
     * Set the job's tech pay to the assigned technician's fixed subcontractor
     * cost. Tech pay is FIXED at that base — it is read-only on the job form, so
     * this is its only writer before payout, and it re-reads on every assignment
     * so a tech SWAP carries the new tech's cost instead of the old tech's.
     * Anything above base belongs on additional_labor_cents, which is added on
     * top (see Job::expected_tech_pay_cents).
     *
     * Two things it never does: touch a job whose payout is already recorded
     * (markTechPaid folded the day's total into this column — rewriting it would
     * restate history), and invent a number for a tech with no fixed cost on
     * file (it blanks instead, so the gap is visible rather than inherited).
     */
    private function applyTechFixedCost(Job $job): void
    {
        if ($job->tech_paid_at !== null) {
            return;
        }

        $job->applyAssignedTechBaseCost();
    }

    /** Assign (or change) the technician without necessarily scheduling. */
    public function assignTechnician(Job $job, ?int $technicianId, Employee $actor): Job
    {
        $job->technician_id = $technicianId;
        $this->applyTechFixedCost($job);
        $job->save();

        activity()->performedOn($job)->causedBy($actor)
            ->event('tech_assigned')->log('Technician assigned');

        // A job that was scheduled before a tech existed now has date+time+tech —
        // send the time confirmation (idempotent; a no-op if it already went out).
        $this->maybeSendTimeConfirmation($job, $actor);
        $this->maybeSendTechJobConfirmation($job, $actor);

        return $job;
    }

    /**
     * Send the time confirmation (with payment instructions) once a job has a
     * date, time, AND technician assigned. Idempotent via the outbox idempotency
     * key, so it's safe to call from both schedule() and assignTechnician() and
     * it will only ever queue/send once. Dispatched immediately.
     */
    private function maybeSendTimeConfirmation(Job $job, Employee $actor): void
    {
        if ($job->scheduled_at === null || $job->technician_id === null) {
            return;
        }

        // Multi-day install, second visit: the time confirmation (with payment
        // instructions) already went out for day one, so this booking gets the
        // "next part of your service is scheduled for …" note instead.
        // Calibration jobs get the same confirmation plus a two-visit note.
        // Use a single template key so the outbox idempotency still fires once.
        $key = match (true) {
            $job->isAwaitingSecondVisit() => TemplateKey::MultiDayNextVisit,
            $job->needsCalibration() => TemplateKey::TimeConfirmationCalibration,
            default => TemplateKey::TimeConfirmation,
        };

        $message = $this->outbox->queueForJob($job, $key, null, $actor->id);
        $this->dispatchNow($message);
    }

    /**
     * Text the assigned technician their copy of the booked job — name, phone,
     * address, arrival time, vehicle, part number and warehouse in one card —
     * the moment the job has both a date/time and a tech. Called from the same
     * two places as maybeSendTimeConfirmation (schedule() and
     * assignTechnician()), since either one can be the step that completes the
     * pair. Dispatched immediately.
     *
     * The warehouse is the one optional field: a job can be booked before
     * anyone has picked where the part comes from, so that line says so rather
     * than sitting blank.
     *
     * Unlike the customer's time confirmation this is NOT once-per-job — the
     * idempotency key carries the appointment instant and the tech, so a
     * double-click sends once while a reschedule or a tech swap sends a fresh,
     * corrected card. It deliberately does not re-send when only the warehouse
     * is filled in later; the tech already knows it was outstanding.
     */
    private function maybeSendTechJobConfirmation(Job $job, Employee $actor): void
    {
        if ($job->scheduled_at === null || $job->technician_id === null) {
            return;
        }

        $tech = $job->technician()->first();
        if ($tech === null) {
            return;
        }

        $message = $this->outbox->queueJobMessageForTech(
            $job,
            $tech,
            TemplateKey::TechJobConfirmation,
            $actor->id,
            extraVars: [
                'warehouse' => TemplateRenderer::warehouseVarForTech($job, $tech->preferredLanguage()),
            ],
            idempotencyKey: "job:{$job->id}:tech:{$tech->id}:tpl:"
                .TemplateKey::TechJobConfirmation->value
                .':at:'.$job->scheduled_at->copy()->utc()->format('Y-m-d\TH:i:s'),
        );

        $this->dispatchNow($message);
    }

    /**
     * Schedule or reschedule a job. Sets scheduled_at + warehouse + (optional)
     * technician, transitions to scheduled, and (re)builds reminders. On the
     * first scheduling of a lead with no original time, queues time_confirmation.
     *
     * $notifyTech: false suppresses the tech's job card, for a caller that
     * sends it itself. Only AI scheduling does — it books through here but
     * texts the card as a turn in its own negotiation thread, so that the
     * transcript is complete and simulate/approve modes still gate it
     * (AiSchedulingService::acceptFrom). The customer's time confirmation is
     * NOT suppressed with it: the AI never owned that one.
     */
    public function schedule(
        Job $job,
        CarbonInterface $scheduledAtUtc,
        Employee $actor,
        ?int $technicianId = null,
        ?int $warehouseId = null,
        ?string $warehouseOther = null,
        ?bool $exactTime = null,
        ?int $windowHours = null,
        bool $notifyTech = true,
    ): Job {
        return DB::transaction(function () use ($job, $scheduledAtUtc, $actor, $technicianId, $warehouseId, $warehouseOther, $exactTime, $windowHours, $notifyTech) {
            $wasScheduled = $job->scheduled_at !== null;
            $isReschedule = $wasScheduled && ! $job->scheduled_at->equalTo($scheduledAtUtc);

            if ($technicianId !== null) {
                $job->technician_id = $technicianId;
                $this->applyTechFixedCost($job);
            }
            if ($warehouseId !== null) {
                $job->warehouse_id = $warehouseId;
            }
            if ($warehouseOther !== null) {
                $job->warehouse_other = $warehouseOther;
            }
            if ($exactTime !== null) {
                $job->scheduled_exact_time = $exactTime;
                // The phrasing choice arrives as a pair: a custom window length
                // (3h/4h) or null = back to the default window / exact time.
                $job->scheduled_window_hours = $exactTime ? null : $windowHours;
            }

            $job->scheduled_at = $scheduledAtUtc->copy()->utc();
            // Re-arm the overdue-completion alert for the (new) window — a job
            // pushed to a later slot should be able to alert again if it, too,
            // slips past its window without being marked complete.
            $job->overdue_completion_alerted_at = null;
            $job->last_scheduled_at = now();
            $job->scheduled_by = $actor->id;
            if (in_array($job->status, [JobStatus::PendingSchedule, JobStatus::Scheduled], true)) {
                $job->status = JobStatus::Scheduled;
            }

            // Booking the job answers any "waiting on the customer" hold, so the
            // stamp comes off with it — otherwise canceling the appointment later
            // would drop the job back onto the hold queue with a stale reason.
            $job->scheduling_hold_at = null;
            $job->scheduling_hold_reason = null;
            $job->scheduling_hold_by = null;

            if ($isReschedule) {
                $job->rescheduled_count = (int) $job->rescheduled_count + 1;
            }
            $job->save();

            if ($isReschedule) {
                activity()->performedOn($job)->causedBy($actor)
                    ->event('rescheduled')->log('Job rescheduled');
            } else {
                activity()->performedOn($job)->causedBy($actor)
                    ->event('scheduled')->log('Job scheduled');
            }

            // Once a job is scheduled with a date, time, AND technician, confirm
            // it (and how to pay) with the customer. Idempotent — only sends once.
            $this->maybeSendTimeConfirmation($job, $actor);

            // The tech gets their own full job card at the same moment. Unlike
            // the customer's confirmation this one DOES re-send on a reschedule,
            // because its details have changed.
            if ($notifyTech) {
                $this->maybeSendTechJobConfirmation($job, $actor);
            }

            // Reschedule cancels old unsent reminders and creates fresh ones.
            $this->reminders->scheduleForJob($job, $actor->id);

            return $job->refresh();
        });
    }

    /**
     * Set where the part gets sourced from, on its own — the rest of
     * schedule() has already happened. Written for AI scheduling, which asks
     * the assigned tech which warehouse they want the part ordered from AFTER
     * the job is booked (docs/ai-scheduling-spec.md §4.5), but there is
     * nothing AI-specific about it.
     *
     * Refuses to overwrite a source someone already chose: a dispatcher's pick
     * outranks a tech's text, and a labor-only job has no part of ours to
     * source at all. Returns whether it actually wrote anything.
     */
    public function setPartsWarehouse(
        Job $job,
        ?int $warehouseId,
        ?string $warehouseOther,
        ?Employee $actor = null,
    ): bool {
        if ($job->isLaborOnly() || $job->warehouse_id !== null || filled($job->warehouse_other)) {
            return false;
        }

        if ($warehouseId === null && blank($warehouseOther)) {
            return false;
        }

        $job->warehouse_id = $warehouseId;
        $job->warehouse_other = $warehouseId !== null ? null : $warehouseOther;
        $job->save();

        $name = $job->warehouse?->name ?? $job->warehouse_other;
        activity()->performedOn($job)->causedBy($actor)
            ->event('warehouse_set')->log("Parts warehouse set to {$name}");

        return true;
    }

    /**
     * Text the customer that their appointment has moved to a new time. Called
     * from the Reschedule action when the dispatcher opts to notify (the time
     * confirmation itself never re-sends on a reschedule, by design). Keyed on
     * the job's rescheduled_count so each distinct reschedule sends exactly one
     * notice — a double-click reuses the same row, while a later reschedule gets
     * a fresh send. Dispatched immediately. No-op when the job isn't scheduled.
     */
    public function sendRescheduleNotice(Job $job, Employee $actor): void
    {
        if ($job->scheduled_at === null) {
            return;
        }

        $idempotencyKey = 'job:'.$job->id.':tpl:'.TemplateKey::AppointmentRescheduled->value
            .':v'.(int) $job->rescheduled_count;

        $message = $this->outbox->queueForJob(
            $job,
            TemplateKey::AppointmentRescheduled,
            null,
            $actor->id,
            idempotencyKey: $idempotencyKey,
        );
        $this->dispatchNow($message);
    }

    /**
     * "Cancel appointment" — the customer needs to reschedule but has no new time
     * yet. Distinct from a reschedule (which just moves the time) and from
     * markLost/cancel (which close the job): this keeps the job OPEN but pulls it
     * off the calendar and back into the scheduling queue. It:
     *   1. clears the appointment instant (so it drops off the calendar) and
     *      resets status to PendingSchedule — the tech assignment is kept so the
     *      same tech is pre-filled when it's re-scheduled;
     *   2. cancels every unsent automated message (reminders, confirmations) —
     *      the appointment no longer exists;
     *   3. texts the customer that we'll follow up with a new date/time;
     *   4. texts the assigned tech that the job came off their schedule;
     *   5. clears the "parts ordered" flag when it was set (so the part shows up
     *      again on Parts to Order after a re-schedule) — the UI prompts the team
     *      to actually call the vendor and cancel.
     *
     * Returns the job with a transient `wasPartsOrdered` attribute so the caller
     * can decide whether to surface the "call to cancel the part order" prompt.
     */
    public function cancelAppointment(Job $job, ?string $reason, Employee $actor, bool $partPickedUp = false): Job
    {
        return DB::transaction(function () use ($job, $reason, $actor, $partPickedUp) {
            // Capture before we mutate anything — the tech to notify, and whether
            // a part order is outstanding.
            $tech = $job->technician;
            $wasPartsOrdered = $job->isPartsOrdered();

            $job->scheduled_at = null;
            $job->status = JobStatus::PendingSchedule->value;
            if ($wasPartsOrdered) {
                // The physical order is being canceled by phone — un-stamp it so a
                // re-schedule re-surfaces the part on Parts to Order. Drop the
                // prepay stamp too: the whole order is being unwound.
                $job->parts_ordered_at = null;
                $job->parts_ordered_by = null;
                $job->parts_paid_at = null;
                $job->parts_paid_by = null;
            }
            // The tech had already picked the part up — flag it for return so it
            // shows on Parts → "Parts returned" until it's brought back. Only stamp
            // it the first time (don't reset an already-pending return on re-cancel).
            if ($partPickedUp && ! $job->needsPartReturn() && ! $job->isPartReturned()) {
                $job->part_return_needed_at = now();
            }
            $job->save();

            // The appointment is gone — kill all unsent automated messages.
            $this->outbox->cancelUnsentForJob($job, null, 'appointment canceled — awaiting new time');

            // Tell the customer we'll follow up with a new time.
            $notice = $this->outbox->queueForJob(
                $job,
                TemplateKey::AppointmentCanceledPendingReschedule,
                null,
                $actor->id,
            );
            $this->dispatchNow($notice);

            // Tell the assigned tech the job came off their schedule. The outbox
            // renders it in the tech's preferred language (English or Spanish).
            if ($tech !== null) {
                $techMsg = $this->outbox->queueJobMessageForTech(
                    $job,
                    $tech,
                    TemplateKey::TechJobCanceled,
                    $actor->id,
                );
                $this->dispatchNow($techMsg);

                // If the tech had already picked up the part, remind them to
                // bring it back (separate text, idempotent per job+template).
                if ($partPickedUp && $job->needsPartReturn()) {
                    $returnMsg = $this->outbox->queueJobMessageForTech(
                        $job,
                        $tech,
                        TemplateKey::TechReturnPart,
                        $actor->id,
                    );
                    $this->dispatchNow($returnMsg);
                }
            }

            activity()->performedOn($job)->causedBy($actor)
                ->event('appointment_canceled')
                ->withProperties(array_filter(['reason' => $reason]))
                ->log('Appointment canceled — moved back to scheduling');

            $job->refresh();
            $job->setAttribute('wasPartsOrdered', $wasPartsOrdered);

            return $job;
        });
    }

    /**
     * "Tech cancelled" — the assigned technician backed out, but the appointment
     * itself stands: same customer, same date, same time/window. We just need to
     * find a new tech to cover the exact same slot. So, unlike cancelAppointment
     * (which pulls the job off the calendar) this touches ONLY the tech:
     *   - clears technician_id (and resets tech_pay_amount_cents so the NEXT
     *     tech's fixed cost is applied on re-assignment — applyTechFixedCost only
     *     fills a null value, and a different tech may cost a different amount);
     *   - keeps scheduled_at, the status (stays Scheduled), the reminders, and any
     *     ordered/prepaid part untouched — nothing about the appointment changed;
     *   - sends NO message to anyone. The customer's appointment is unchanged, so
     *     there is nothing to tell them, and the tech is the one who cancelled.
     *
     * The job then surfaces on the "Needs tech assigned" queue (Scheduled with no
     * technician) until someone re-assigns a tech via Assign tech / Reschedule.
     */
    public function techCancelled(Job $job, ?string $reason, Employee $actor): Job
    {
        return DB::transaction(function () use ($job, $reason, $actor) {
            $job->technician_id = null;
            $job->tech_pay_amount_cents = null;
            $job->save();

            activity()->performedOn($job)->causedBy($actor)
                ->event('tech_canceled')
                ->withProperties(array_filter(['reason' => $reason]))
                ->log('Tech cancelled — job needs a new tech assigned');

            return $job->refresh();
        });
    }

    /**
     * Multi-day install: the tech finished DAY ONE. This is deliberately NOT a
     * completion — `work_completed_at` stays null, no completion text goes out,
     * no payment preference is asked, no review drip starts. All of that waits
     * for the final visit, which runs the normal markWorkComplete flow.
     *
     * What it does: stamps the stage marker, cancels day one's unsent reminders,
     * and pulls the job off the calendar back into PendingSchedule so it shows up
     * on the needs-scheduling queue until the second visit has a date. The tech,
     * warehouse and part stay put (same job, same install). The caller either
     * schedules the second visit right away — which texts the customer the
     * MultiDayNextVisit note (see maybeSendTimeConfirmation) — or leaves it for
     * whenever the customer gives us a date.
     */
    public function completeFirstInstallDay(Job $job, Employee $actor): Job
    {
        return DB::transaction(function () use ($job, $actor) {
            $this->assertTechnicianAssigned($job, 'marking the first install day complete');

            $job->multi_day_first_completed_at = now();
            $job->multi_day_first_completed_by = $actor->id;
            $job->scheduled_at = null;
            $job->status = JobStatus::PendingSchedule->value;
            // The day-one window is behind us; re-arm for the second visit.
            $job->overdue_completion_alerted_at = null;
            $job->save();

            // Day one's reminders are spent — the second visit builds its own.
            $this->outbox->cancelUnsentForJob($job, ['reminder'], 'first install day completed');

            activity()->performedOn($job)->causedBy($actor)
                ->event('multi_day_first_day_completed')
                ->log('First install day complete - awaiting the second visit');

            return $job->refresh();
        });
    }

    /**
     * Calibration jobs: never open the review call-first queue here — it waits
     * until ops confirms the partner finished calibration
     * (CalibrationService::confirm). Instead we enter the calibration pipeline
     * and send the partner-outreach text. Every other job opens the queue the
     * instant work is marked complete — dispatch is then prompted (see
     * JobActions::markWorkComplete) to call the customer right away.
     */
    public function markWorkComplete(Job $job, Employee $actor, bool $sendCompleteText = true): Job
    {
        return DB::transaction(function () use ($job, $actor, $sendCompleteText) {
            $this->assertTechnicianAssigned($job, 'marking work complete');

            $job->work_completed_at = now();
            $job->status = JobStatus::WorkCompleted->value;
            $job->save();

            // Never send reminders after work is completed.
            $this->outbox->cancelUnsentForJob($job, ['reminder'], 'work completed');

            // Thank-you / wrap-up text, sent as soon as the job is marked complete
            // — but only when no payment-preference-specific text will also go
            // out for this same completion (see JobActions::markWorkComplete).
            // A job that was ALREADY paid before completion owes nothing, so it
            // gets the payment-free JobCompletePaid body instead of the Zelle one.
            if ($sendCompleteText) {
                $key = $job->isFullyPaid() ? TemplateKey::JobCompletePaid : TemplateKey::JobComplete;
                $complete = $this->outbox->queueForJob($job, $key, null, $actor->id);
                $this->dispatchNow($complete);
            }

            activity()->performedOn($job)->causedBy($actor)
                ->event('work_completed')->log('Work marked complete');

            // ADAS calibration: install is only half the job. Enter the partner
            // pipeline (second SMS + partner email) and skip review until later.
            if ($job->needsCalibration()) {
                app(CalibrationService::class)
                    ->enterAfterInstall($job->refresh(), $actor);

                return $this->maybeComplete($job->refresh(), $actor);
            }

            // Open the call-first review queue right away — idempotent per job,
            // so a re-completion (shouldn't happen, but just in case) is a no-op.
            app(ReviewDripService::class)->startAwaitingCall($job, $actor);

            return $this->maybeComplete($job, $actor);
        });
    }

    /**
     * Record how the customer said they'll pay (confirmed on "Mark work
     * complete" for unpaid jobs) and text them the matching "Job Complete - …"
     * text: Zelle reuses the original `job_complete` key/body (the live prod
     * completion text); Venmo/Cash/Check are their own `job_complete_*` keys,
     * cloned from that same body with no entity-sourced merge var required.
     * Stripe/CC → nothing here — the card link (and its own JobCompleteCreditCard
     * text) is created/re-sent by the caller (it's a network call that must stay
     * outside our transactions). Idempotent per job+template, so re-confirming
     * the same preference never double-texts. Returns the queued text row, or
     * null when no text applies / it was blocked.
     */
    public function confirmPaymentPreference(Job $job, PaymentMethod $preference, Employee $actor): ?ScheduledMessage
    {
        $job->payment_preference = $preference->value;
        $job->save();

        activity()->performedOn($job)->causedBy($actor)
            ->event('payment_preference_confirmed')
            ->log('Payment preference confirmed: '.$preference->label());

        $key = match ($preference) {
            PaymentMethod::Zelle => TemplateKey::JobComplete,
            PaymentMethod::Venmo => TemplateKey::JobCompleteVenmo,
            PaymentMethod::Cash => TemplateKey::JobCompleteCash,
            PaymentMethod::Check => TemplateKey::JobCompleteCheck,
            default => null,
        };

        if ($key === null) {
            return null;
        }

        $message = $this->outbox->queueForJob($job, $key, null, $actor->id);
        $this->dispatchNow($message);

        return $message;
    }

    public function recordPayment(
        Job $job,
        int $amountCents,
        PaymentMethod $method,
        ?string $reference,
        ?string $notes,
        Employee $actor,
    ): Job {
        return DB::transaction(function () use ($job, $amountCents, $method, $reference, $notes, $actor) {
            $this->assertTechnicianAssigned($job, 'recording payment');

            // Each Record payment ADDS a line to the ledger — a deposit now, the
            // balance later, possibly via different methods. Whether this line
            // finishes the quote or leaves a balance due, the same plumbing runs.
            $payment = $job->payments()->create([
                'amount_cents' => $amountCents,
                'method' => $method->value,
                'reference' => $reference,
                'notes' => $notes,
                'recorded_by' => $actor->id,
                'collected_at' => now(),
            ]);

            $this->syncPaymentSummary($job);

            // Only THIS line's cash rides onto the tech balance (old=0: it's a new,
            // independent collection, not an edit of the whole running total).
            $lineCash = $method === PaymentMethod::Cash ? $amountCents : 0;
            $this->applyCashToTechBalance($job, 0, $lineCash, $reference, $actor);

            activity()->performedOn($job)->causedBy($actor)
                ->event('payment_recorded')
                ->log($job->isFullyPaid() ? 'Payment recorded' : 'Partial payment recorded ('.Money::format($job->balanceDueCents()).' balance due)');

            // The review call-first queue opens at completion (or, for a
            // calibration job, at calibration confirm) — never here.
            return $this->maybeComplete($job, $actor);
        });
    }

    /**
     * Recompute a job's payment-summary columns from its ledger lines: total
     * collected (sum), the latest line's method/reference/notes/recorder, and the
     * first line's timestamp as "payment received at". Every downstream consumer
     * (AR, bank matching, ROAS, receipts) reads these, so they stay authoritative.
     * With no lines left the job reads unpaid again.
     */
    private function syncPaymentSummary(Job $job): void
    {
        $lines = $job->payments()->get();

        if ($lines->isEmpty()) {
            $job->amount_collected_cents = 0;
            $job->payment_method = null;
            $job->payment_reference = null;
            $job->payment_notes = null;
            $job->payment_received_at = null;
            $job->payment_recorded_by = null;
            $job->save();

            return;
        }

        $latest = $lines->last();

        $job->amount_collected_cents = (int) $lines->sum('amount_cents');
        $job->payment_method = $latest->method->value;
        $job->payment_reference = $latest->reference;
        $job->payment_notes = $latest->notes;
        $job->payment_received_at = $lines->first()->collected_at;
        $job->payment_recorded_by = $latest->recorded_by;
        $job->save();
    }

    /**
     * Remove a payment line (a mis-entered deposit, say) and re-sync the summary.
     * Reverses that line's cash off the tech balance if it was a cash line. Does
     * NOT resurrect a completed job — completion is left as-is (an admin can
     * re-open separately); it only corrects the money.
     */
    public function deletePayment(JobPayment $payment, Employee $actor): Job
    {
        return DB::transaction(function () use ($payment, $actor) {
            $job = $payment->job;

            if ($payment->method === PaymentMethod::Cash) {
                // Back the cash off the tech's balance (floors at 0 in the helper).
                $this->applyCashToTechBalance($job, (int) $payment->amount_cents, 0, $payment->reference, $actor);
            }

            $payment->delete();
            $this->syncPaymentSummary($job);

            activity()->performedOn($job)->causedBy($actor)
                ->event('payment_deleted')
                ->log('Payment line removed ('.Money::format((int) $payment->amount_cents).')');

            return $job;
        });
    }

    /**
     * Manually confirm we actually received a recorded (non-cash) payment — the
     * Accounts Receivable "I've eyeballed the Zelle/Venmo/Stripe deposit" ack when
     * no Chase transaction has been auto-matched yet. Purely a verification stamp;
     * it doesn't touch amounts, completion, or the tech balance. Idempotent.
     */
    public function confirmPaymentReceived(Job $job, Employee $actor): Job
    {
        if (! $job->isPaid() || $job->payment_confirmed_at !== null) {
            return $job;
        }

        $job->payment_confirmed_at = now();
        $job->payment_confirmed_by = $actor->id;
        $job->save();

        activity()->performedOn($job)->causedBy($actor)
            ->event('payment_confirmed')->log('Payment receipt confirmed');

        return $job;
    }

    /**
     * Record a customer's Stripe card payment straight from the Stripe webhook
     * (checkout.session.completed). Because Stripe told us the money actually
     * landed, we ALSO auto-confirm it received — no bank matching needed for
     * cards (Stripe deposits arrive as batched payouts, not per-charge). System
     * action (no logged-in actor). Idempotent per job via `stripe_paid_at`:
     * Stripe retries the webhook, but the second delivery is a no-op.
     *
     * Records the job's OUTSTANDING BALANCE (quote − already collected), not the
     * card `amount_total` — the card surcharge the customer paid on top is their
     * processing fee, not our revenue, so the ledger stays quote-accurate. A job
     * already fully paid (balance ≤ 0) is left untouched.
     */
    public function recordStripeWebhookPayment(Job $job, ?string $paymentRef): Job
    {
        return DB::transaction(function () use ($job, $paymentRef) {
            if ($job->stripe_paid_at !== null) {
                return $job; // already handled a delivery of this event
            }

            $amountCents = $job->balanceDueCents();
            if ($amountCents <= 0) {
                // Nothing left to collect — still stamp so retries stay no-ops.
                $job->stripe_paid_at = now();
                $job->stripe_payment_ref = $paymentRef;
                $job->stripe_invoice_status = 'paid';
                $job->save();

                return $job;
            }

            $job->payments()->create([
                'amount_cents' => $amountCents,
                'method' => PaymentMethod::Stripe->value,
                'reference' => $paymentRef,
                'notes' => 'Auto-recorded from Stripe (card payment link paid)',
                'recorded_by' => null,
                'collected_at' => now(),
            ]);

            $this->syncPaymentSummary($job);

            $job->stripe_paid_at = now();
            $job->stripe_payment_ref = $paymentRef;
            $job->stripe_invoice_status = 'paid';
            // Stripe is authoritative for the card, so this counts as verified
            // received — clears the Accounts Receivable "unconfirmed" bucket
            // without waiting for a Chase match.
            if ($job->payment_confirmed_at === null) {
                $job->payment_confirmed_at = now();
                $job->payment_confirmed_by = null;
            }
            $job->save();

            activity()->performedOn($job)
                ->event('stripe_payment_received')
                ->log('Card payment received via Stripe ('.Money::format($amountCents).') — auto-confirmed');

            return $this->maybeComplete($job);
        });
    }

    /**
     * Mark that we've collected the Xpro-collected money (Klarna / SwiftSwipe /
     * Other - Xpro Team) back from the Xpro team for this job. Stamped from the
     * Owed from Xpro finance page. Idempotent — a job already collected, or one
     * not paid via an Xpro method, is left untouched.
     */
    public function markXproCollected(Job $job, Employee $actor): Job
    {
        if (! $job->isOwedFromXpro()) {
            return $job;
        }

        $job->xpro_collected_at = now();
        $job->xpro_collected_by = $actor->id;
        // Re-arm the overdue escalation: if this job is ever owed from Xpro again
        // (a payment corrected/re-added), it can alert afresh rather than staying
        // silent on a stale stamp.
        $job->xpro_overdue_alerted_at = null;
        $job->save();

        activity()->performedOn($job)->causedBy($actor)
            ->event('xpro_collected')->log('Xpro collection recorded');

        return $job;
    }

    /**
     * Record the insurance carrier's payment on an insurance job. This is the
     * balance the insurer covers, separate from the customer's deductible (which
     * goes through recordPayment like any other job). It is purely an orthogonal
     * fact for tracking on the Insurance tab — it does NOT gate job completion,
     * which still keys off the customer payment + work + tech payout.
     */
    public function recordInsurancePayment(
        Job $job,
        int $amountCents,
        ?string $reference,
        ?string $notes,
        Employee $actor,
        ?string $carrier = null,
        ?string $claimNumber = null,
    ): Job {
        return DB::transaction(function () use ($job, $amountCents, $reference, $notes, $actor, $carrier, $claimNumber) {
            $job->insurance_amount_cents = $amountCents;
            $job->insurance_reference = $reference;
            $job->insurance_notes = $notes;
            $job->insurance_paid_at = now();
            $job->insurance_paid_recorded_by = $actor->id;

            // The carrier/claim are usually already on the job, but the check is
            // often the moment they're actually known — fill them in if supplied,
            // never blank out what's already there.
            if (filled($carrier)) {
                $job->insurance_carrier = $carrier;
            }
            if (filled($claimNumber)) {
                $job->insurance_claim_number = $claimNumber;
            }

            $job->save();

            activity()->performedOn($job)->causedBy($actor)
                ->event('insurance_paid')->log('Insurance payment recorded');

            return $job;
        });
    }

    /**
     * "Text card payment link": build a Stripe Payment Link for the job quote
     * plus a card-processing surcharge (one line item) and text the customer the
     * hosted pay-by-card URL. Uses Payment Links (not Invoices) so Stripe does not
     * add the Invoicing fee. Card data never touches this app — the customer pays
     * on Stripe's hosted page. The payment itself is still recorded through
     * recordPayment (method = Stripe/CC); these fields only track the link we sent.
     *
     * Column names still say stripe_invoice_* for history (plink_ ids + URLs live
     * there). The Stripe network call runs OUTSIDE the DB transaction. Returns the
     * queued text row, or null when the link was created but the text was blocked
     * (customer opted out / no number). Throws when the link can't be created
     * (no quote, Stripe not configured, or the API rejected the request).
     *
     * $template defaults to the standalone "Text card payment link" body
     * (PaymentLink); the "Mark work complete" → Credit Card flow passes
     * JobCompleteCreditCard instead, which carries the same {payment_link}/
     * {payment_total} merge vars inside the fuller completion text.
     */
    public function createAndTextStripeInvoice(Job $job, Employee $actor, TemplateKey $template = TemplateKey::PaymentLink): ?ScheduledMessage
    {
        if ((int) ($job->quote_amount_cents ?? 0) <= 0) {
            throw new \RuntimeException('Set a quote on the job before sending a card payment link.');
        }

        $stripe = app(StripeClient::class);
        if (! $stripe->configured()) {
            throw new \RuntimeException('Stripe is not configured. Add the secret key in Admin → Integrations.');
        }

        $job->loadMissing('customer');
        $surchargePct = (float) config('glass.stripe.surcharge_percent', 3);
        $amountCents = (int) round((int) $job->quote_amount_cents * (1 + $surchargePct / 100));
        $description = $this->stripeLineItemLabel($job);

        $result = $stripe->createPaymentLink(
            amountCents: $amountCents,
            description: $description,
            metadata: ['job_id' => $job->id, 'job_number' => (string) $job->job_number],
        );

        if ($result === null) {
            throw new \RuntimeException('Stripe could not create the payment link — check the API key and the log.');
        }

        DB::transaction(function () use ($job, $result, $amountCents, $actor) {
            // Payment Links do not create a Customer up front; leave null.
            $job->stripe_customer_id = null;
            $job->stripe_invoice_id = $result['payment_link_id'];
            $job->stripe_invoice_url = $result['url'];
            $job->stripe_invoice_amount_cents = $amountCents;
            $job->stripe_invoice_status = $result['status'];
            $job->stripe_invoice_created_at = now();
            $job->stripe_invoice_created_by = $actor->id;
            $job->save();

            activity()->performedOn($job)->causedBy($actor)
                ->event('stripe_invoice_created')
                ->log('Card payment link created ('.Money::format($amountCents).')');
        });

        return $this->textStripeInvoiceLink($job->refresh(), $actor, 'texted', $template);
    }

    /**
     * Re-text the existing Stripe Payment Link to the customer without creating a
     * new one. Throws when no link exists yet.
     */
    public function resendStripeInvoiceLink(Job $job, Employee $actor, TemplateKey $template = TemplateKey::PaymentLink): ?ScheduledMessage
    {
        if (blank($job->stripe_invoice_url)) {
            throw new \RuntimeException('No card payment link exists yet for this job.');
        }

        return $this->textStripeInvoiceLink($job, $actor, 're-sent', $template);
    }

    /** Queue + immediately dispatch the payment-link text; log it when it goes. */
    private function textStripeInvoiceLink(Job $job, Employee $actor, string $verb, TemplateKey $template = TemplateKey::PaymentLink): ?ScheduledMessage
    {
        $message = $this->outbox->queueManual($job, $template, $actor->id);
        $this->dispatchNow($message);

        if ($message !== null) {
            activity()->performedOn($job)->causedBy($actor)
                ->event('stripe_invoice_texted')->log("Card payment link {$verb} to customer");
        }

        return $message;
    }

    /**
     * The single Stripe line item's description: the vehicle (when known) + the
     * configured service label. The card surcharge is folded into the amount but
     * NOT spelled out here — the disclosure lives in the SMS (payment_link
     * template) instead, so the line item reads cleanly.
     */
    private function stripeLineItemLabel(Job $job): string
    {
        $vehicle = trim(implode(' ', array_filter([
            $job->vehicle_year, $job->vehicle_make, $job->vehicle_model,
        ]))) ?: ($job->vehicle_raw ?: null);

        $label = trim(($vehicle ? $vehicle.' ' : '').config('glass.stripe.line_item_label', 'auto glass service'));

        return ucfirst($label);
    }

    /**
     * Guard: a job must have a technician before it can be scheduled, marked
     * complete, or have a payment recorded. Cash tracking (and pay-owed) is
     * meaningless without a tech to attribute it to.
     */
    private function assertTechnicianAssigned(Job $job, string $action): void
    {
        if ($job->technician_id === null) {
            throw new \RuntimeException("Assign a technician before {$action}.");
        }
    }

    /**
     * Cash a tech collects is money they hold that belongs to us, so it rides on
     * their rolling balance the instant the payment is recorded — independent of
     * End-of-Day settlement. Applies only the NET change ($newCash − $oldCash) so
     * editing an amount, or switching a payment to/from cash, adjusts rather than
     * double-counts. Non-cash methods contribute 0. Each move that actually shifts
     * the balance writes a `cash_collected` audit row pointing at the job, so a
     * tech's balance can be explained line-by-line. Never drops the balance below 0.
     */
    private function applyCashToTechBalance(Job $job, int $oldCashCents, int $newCashCents, ?string $reference, Employee $actor): void
    {
        $delta = $newCashCents - $oldCashCents;
        if ($delta === 0 || $job->technician_id === null) {
            return;
        }

        $tech = $job->technician()->first();
        if ($tech === null) {
            return;
        }

        $before = (int) ($tech->cash_balance_cents ?? 0);
        $after = max(0, $before + $delta);
        $applied = $after - $before;
        if ($applied === 0) {
            return;
        }

        $tech->cash_balance_cents = $after;
        $tech->save();

        TechSettlement::create([
            'technician_id' => $tech->id,
            'type' => 'cash_collected',
            'for_date' => Timezone::now()->format('Y-m-d'),
            'job_id' => $job->id,
            'amount_cents' => abs($applied),
            'direction' => $applied > 0 ? 'cash_to_balance' : 'cash_reversed',
            'tech_pay_owed_cents' => 0,
            'cash_collected_cents' => $newCashCents,
            'balance_before_cents' => $before,
            'balance_after_cents' => $after,
            'job_count' => 1,
            'method' => PaymentMethod::Cash->value,
            'reference' => $reference,
            'note' => ($applied > 0 ? 'Cash collected on ' : 'Cash adjusted on ').$job->job_number,
            'settled_at' => now(),
            'recorded_by' => $actor->id,
        ]);
    }

    /**
     * Fix the two values a part/glass mismatch is read from — the glass position
     * and the PRIMARY part number — from the scheduling prompt, so the operator
     * can resolve it without leaving the modal. Only the values actually passed
     * are touched (null = leave alone), and nothing is written when neither
     * changed, so the ordinary scheduling path stays a no-op.
     *
     * The part label is rewritten in place on parts_json[0] so its cost and
     * "tech paid" flag survive; part_number is kept in sync with it, exactly as
     * JobForm::fromPartsInput() does. A job with no parts row yet gets one.
     */
    public function correctPartGlass(Job $job, ?string $glassPosition, ?string $partNumber, Employee $actor): Job
    {
        $position = $glassPosition === null ? null : trim($glassPosition);
        $part = $partNumber === null ? null : trim($partNumber);

        $changes = [];

        if ($position !== null && $position !== '' && $position !== $job->glass_position) {
            $changes['glass_position'] = $position;
        }

        if ($part !== null && $part !== '' && $part !== ($job->partLabels()[0] ?? null)) {
            $parts = $job->parts_json ?? [];
            if ($parts === []) {
                $parts = [['label' => $part, 'cost_cents' => $job->part_cost_cents]];
            } else {
                $parts[0]['label'] = $part;
            }

            $changes['parts_json'] = $parts;
            $changes['part_number'] = $part;
        }

        if ($changes === []) {
            return $job;
        }

        $before = ['glass_position' => $job->glass_position, 'part_number' => $job->partLabels()[0] ?? null];

        $job->fill($changes);

        // An insurance WINDSHIELD always needs calibration — correcting the
        // position INTO a windshield has to force the stored flag the same way
        // intake does, or the column and Job::needsCalibration() disagree.
        if ($job->is_insurance && $job->isWindshield()) {
            $job->needs_calibration = true;
        }

        $job->save();

        activity()->performedOn($job)->causedBy($actor)
            ->withProperties(['before' => $before, 'after' => [
                'glass_position' => $job->glass_position,
                'part_number' => $job->partLabels()[0] ?? null,
            ]])
            ->event('part_glass_corrected')
            ->log('Part number / glass position corrected');

        return $job;
    }

    /**
     * Dispatch's explicit "these still don't agree, but schedule it anyway".
     * Stamps the override against the EXACT mismatch being excused (so editing
     * the job into a different one prompts again), writes the required reason
     * into the job's note feed, and raises the manager-review Issue.
     *
     * No-op when there is nothing to override — the operator fixed the values in
     * the same submit, or they never disagreed.
     */
    public function overridePartGlassMismatch(Job $job, string $note, Employee $actor): Job
    {
        $mismatch = $job->partGlassMismatch();

        if ($mismatch === null) {
            return $job;
        }

        $job->part_glass_override_at = now();
        $job->part_glass_override_by = $actor->id;
        $job->part_glass_override_note = $note;
        $job->part_glass_override_signature = PartNumberGlass::signature($mismatch);
        $job->save();

        JobNote::create([
            'job_id' => $job->id,
            'author_id' => $actor->id,
            'body' => 'Scheduled over a part/glass mismatch. '
                .PartNumberGlass::sentence($mismatch)."\nReason: ".$note,
        ]);

        activity()->performedOn($job)->causedBy($actor)
            ->withProperties(['mismatch' => $mismatch, 'note' => $note])
            ->event('part_glass_override')
            ->log('Part/glass mismatch overridden at scheduling');

        Issue::raise(IssueType::PartGlassMismatch, $job, [
            'job_number' => $job->job_number,
            'customer_name' => $job->customer_name_snapshot,
            'entity' => $job->entity?->name,
            'part_number' => $mismatch['part_number'],
            'part_reads' => $mismatch['part_label'],
            'glass_position' => $mismatch['position'],
            'override_note' => $note,
            'overridden_by' => $actor->name,
        ], $actor->id);

        return $job;
    }

    /**
     * Park an unscheduled job — the lead is real and the work is still ours to
     * book, but we are waiting to hear back from the customer (they have to check
     * a schedule, confirm the quote, call their insurer). The job leaves the
     * "needs scheduling" queue (board tab, Open Tasks, the ops digests) and moves
     * onto the "On hold" queue instead, where the daily digest keeps naming it so
     * it can't be quietly forgotten.
     *
     * The reason is REQUIRED — a hold with no reason is indistinguishable from a
     * job somebody dropped, which is the failure this is meant to prevent.
     * Only a job still pending schedule can be held; a scheduled or closed job
     * has nothing to wait for. Re-holding an already-held job updates the reason
     * and re-stamps the clock (the customer was chased again).
     */
    public function placeOnSchedulingHold(Job $job, string $reason, Employee $actor): Job
    {
        if ($job->status !== JobStatus::PendingSchedule) {
            return $job;
        }

        $job->scheduling_hold_at = now();
        $job->scheduling_hold_reason = trim($reason);
        $job->scheduling_hold_by = $actor->id;
        $job->save();

        activity()->performedOn($job)->causedBy($actor)
            ->withProperties(['reason' => $job->scheduling_hold_reason])
            ->event('scheduling_hold')
            ->log('Put on hold — waiting on the customer');

        return $job;
    }

    /**
     * Take the hold off — the customer came back to us. The job returns to the
     * needs-scheduling queue exactly where it was. Idempotent.
     *
     * Also called from schedule(): booking the job answers the hold, so a held
     * job that gets a date must not stay stamped (nothing reads the stamp on a
     * scheduled job, but a stale one would resurface the moment the appointment
     * was canceled back to pending).
     */
    public function releaseSchedulingHold(Job $job, Employee $actor, ?string $note = null): Job
    {
        if ($job->scheduling_hold_at === null) {
            return $job;
        }

        $job->scheduling_hold_at = null;
        $job->scheduling_hold_reason = null;
        $job->scheduling_hold_by = null;
        $job->save();

        activity()->performedOn($job)->causedBy($actor)
            ->withProperties(array_filter(['note' => $note]))
            ->event('scheduling_hold_released')
            ->log('Hold released — back on the scheduling queue');

        return $job;
    }

    /**
     * Stamp the job's part as ordered (Operations → Parts to Order, or the
     * post-schedule prompt). Idempotent — a job already stamped is left alone,
     * so re-opening the modal on an already-ordered job never resets it.
     */
    public function markPartsOrdered(Job $job, Employee $actor): Job
    {
        if ($job->isPartsOrdered()) {
            return $job;
        }

        $job->parts_ordered_at = now();
        $job->parts_ordered_by = $actor->id;
        $job->save();

        activity()->performedOn($job)->causedBy($actor)
            ->event('parts_ordered')->log('Part(s) ordered');

        return $job;
    }

    /**
     * Undo the "ordered" stamp — the part was marked ordered by mistake, or the
     * order was called off. The job goes back onto Parts to Order.
     *
     * The prepay stamp is dropped with it, exactly as cancelAppointment does:
     * a prepay only exists because of an order, so an unordered-but-prepaid job
     * is a state nothing else understands (scopePrepayPending needs both), and
     * marking the part ordered again re-asks for the prepay. Idempotent.
     */
    public function undoPartsOrdered(Job $job, Employee $actor, ?string $reason = null): Job
    {
        if (! $job->isPartsOrdered()) {
            return $job;
        }

        $wasPaid = $job->isPartsPaid();

        $job->parts_ordered_at = null;
        $job->parts_ordered_by = null;
        $job->parts_paid_at = null;
        $job->parts_paid_by = null;
        $job->save();

        activity()->performedOn($job)->causedBy($actor)
            ->withProperties(array_filter([
                'reason' => $reason,
                'prepay_cleared' => $wasPaid ?: null,
            ]))
            ->event('parts_order_undone')
            ->log('Part order undone'.($wasPaid ? ' (prepay stamp cleared too)' : ''));

        return $job;
    }

    /**
     * Stamp the job's part as PAID FOR — the prepay we make to an "Other"
     * supplier (a dealer / one-off place we have no account with) so it releases
     * the glass. Idempotent — a job already paid is left alone.
     */
    public function markPartsPaid(Job $job, Employee $actor): Job
    {
        if ($job->isPartsPaid()) {
            return $job;
        }

        $job->parts_paid_at = now();
        $job->parts_paid_by = $actor->id;
        $job->save();

        activity()->performedOn($job)->causedBy($actor)
            ->event('parts_paid')->log('Part(s) paid for');

        return $job;
    }

    /**
     * Stamp a picked-up part as RETURNED — the tech brought it back after a
     * cancellation, clearing it off the Parts → "Parts returned" tab. Idempotent,
     * and a no-op on jobs that were never flagged for return.
     */
    public function markPartReturned(Job $job, Employee $actor): Job
    {
        if (! $job->needsPartReturn()) {
            return $job;
        }

        $job->part_returned_at = now();
        $job->part_returned_by = $actor->id;
        $job->save();

        activity()->performedOn($job)->causedBy($actor)
            ->event('part_returned')->log('Picked-up part returned');

        return $job;
    }

    public function markTechPaid(
        Job $job,
        int $amountCents,
        ?PaymentMethod $method,
        ?string $reference,
        Employee $actor,
    ): Job {
        return DB::transaction(function () use ($job, $amountCents, $method, $reference, $actor) {
            // The recorded payout is the tech's total pay (the dialogs default to
            // base + additional labor). Additional labor is part of that amount,
            // so fold it into tech_pay_amount_cents and clear the separate line —
            // otherwise gross margin / ROAS profit, which subtract both, would
            // double-count it. The activity log keeps the prior breakdown.
            $job->tech_pay_amount_cents = $amountCents;
            $job->additional_labor_cents = null;
            $job->additional_labor_reason = null;
            $job->tech_paid_method = $method?->value;
            $job->tech_paid_reference = $reference;
            $job->tech_paid_at = now();
            $job->tech_paid_recorded_by = $actor->id;
            $job->save();

            activity()->performedOn($job)->causedBy($actor)
                ->event('tech_paid')->log('Technician payout recorded');

            return $this->maybeComplete($job, $actor);
        });
    }

    /**
     * Jobs that make up a technician's End-of-Day total for one local (Pacific)
     * day: completed that day, not yet tech-paid, and not canceled/lost. Customer
     * payment does NOT gate this — we pay techs for jobs they complete. Cash the
     * tech collected is netted when the customer payment is recorded; if it isn't
     * recorded yet, cash collected reads $0 and the tech is paid full labor.
     *
     * @return Collection<int, Job>
     */
    public function techDayJobs(Employee $tech, CarbonInterface|string $localDate)
    {
        [$start, $end] = Timezone::localDayUtcRange($localDate);

        return $tech->jobsAsTechnician()->settleable()
            ->whereBetween('work_completed_at', [$start, $end])
            ->orderBy('work_completed_at')
            ->get();
    }

    /**
     * Every job a technician COMPLETED on one local (Pacific) day — paid or not,
     * tech-settled or not — excluding canceled/lost. This is the set the
     * End-of-Day "Pay owed" total and its breakdown are built from (so already
     * tech-paid jobs still appear), unlike techDayJobs() which is the still-open
     * subset that settlement actually acts on.
     *
     * @return Collection<int, Job>
     */
    public function techDayCompletedJobs(Employee $tech, CarbonInterface|string $localDate)
    {
        [$start, $end] = Timezone::localDayUtcRange($localDate);

        return $tech->jobsAsTechnician()
            ->whereNotNull('work_completed_at')
            ->whereNotIn('status', [JobStatus::Canceled->value, JobStatus::Lost->value])
            ->whereBetween('work_completed_at', [$start, $end])
            ->orderBy('work_completed_at')
            ->get();
    }

    /**
     * Settle one technician's End-of-Day for a given local day. Cash they
     * collected already rode onto their balance when each payment was recorded,
     * so settlement is just the labor we owe for the day, auto-offset against the
     * rolling balance:
     *
     *   payout   = max(0, laborOwed − priorBalance)  ← we hand the tech this
     *   newBal   = max(0, priorBalance − laborOwed)   ← cash they still owe us
     *
     * So a day where we'd owe them first pays down what they owe us. Stamps each
     * contributing job tech-paid (via markTechPaid → completion + ROAS stay
     * consistent) and writes one TechSettlement audit row. Returns null when the
     * tech has nothing to settle that day.
     *
     * ⚠️ Pass a FRESHLY QUERIED $tech. Balance-moving calls elsewhere (e.g.
     * recordPayment → applyCashToTechBalance) fetch and save their own Employee
     * instance rather than mutating whatever instance the caller is holding —
     * so a $tech fetched before one of those calls has a stale cash_balance_cents
     * in memory, and $before here would read that stale value and overwrite the
     * real one. The End-of-Day UI is safe because Filament re-queries the record
     * fresh; call sites outside the UI must ->refresh() first.
     */
    public function settleTechDay(
        Employee $tech,
        CarbonInterface|string $localDate,
        ?PaymentMethod $method,
        ?string $reference,
        ?string $note,
        Employee $actor,
    ): ?TechSettlement {
        $date = $localDate instanceof CarbonInterface ? $localDate->format('Y-m-d') : (string) $localDate;

        return DB::transaction(function () use ($tech, $date, $method, $reference, $note, $actor) {
            $jobs = $this->techDayJobs($tech, $date);

            if ($jobs->isEmpty()) {
                return null;
            }

            // Cash the tech collected already rolled onto their balance when each
            // payment was recorded, so the day's settlement is purely what we owe
            // — labor PLUS any parts the tech fronted — netted against that running
            // balance (owed − balance auto-offset). markTechPaid only folds labor
            // into tech_pay_amount_cents; the parts reimbursement stays on its own
            // column so gross margin / ROAS never double-count it.
            $owedCents = (int) $jobs->sum(fn (Job $job) => $job->total_owed_to_tech_cents);

            $before = $tech->cashOwedCents();
            $payout = max(0, $owedCents - $before);
            $after = max(0, $before - $owedCents);

            foreach ($jobs as $job) {
                $this->markTechPaid($job, $job->expected_tech_pay_cents, $method, $reference, $actor);
            }

            $tech->cash_balance_cents = $after;
            $tech->save();

            $settlement = TechSettlement::create([
                'technician_id' => $tech->id,
                'type' => 'eod_payout',
                'for_date' => $date,
                'amount_cents' => $payout,
                'direction' => $payout > 0 ? 'paid_to_tech' : 'even',
                'tech_pay_owed_cents' => $owedCents,
                'cash_collected_cents' => 0,
                'balance_before_cents' => $before,
                'balance_after_cents' => $after,
                'job_count' => $jobs->count(),
                'settled_job_ids' => $jobs->pluck('id')->all(),
                'method' => $method?->value,
                'reference' => $reference,
                'note' => $note,
                'settled_at' => now(),
                'recorded_by' => $actor->id,
            ]);

            activity()->performedOn($settlement)->causedBy($actor)
                ->event('tech_day_settled')
                ->log('Tech end-of-day settled for '.$date);

            return $settlement;
        });
    }

    /**
     * Undo an eod_payout settlement: restores the tech's balance to what it was
     * before the settlement and un-stamps tech-paid on exactly the jobs that
     * settlement paid (settled_job_ids), so they become open again — e.g. after
     * fixing a tech-fronted-part flag that was ticked too late, "Mark
     * paid"/"Mark confirmed" can be run again and will pick up the corrected
     * total. Refuses to undo if the tech's balance has moved since (another
     * settlement or cash collection happened after this one) — that would make
     * "restore to balance_before_cents" wrong; unwind the later event first.
     * The settlement row is kept (never deleted), stamped undone_at/undone_by.
     */
    public function undoSettleTechDay(TechSettlement $settlement, Employee $actor): TechSettlement
    {
        return DB::transaction(function () use ($settlement, $actor) {
            if ($settlement->type !== 'eod_payout') {
                throw new \InvalidArgumentException('Only end-of-day settlements can be undone.');
            }

            if ($settlement->isUndone()) {
                throw new \InvalidArgumentException('This settlement was already undone.');
            }

            $tech = $settlement->technician;

            if ($tech->cash_balance_cents !== $settlement->balance_after_cents) {
                throw new \RuntimeException(
                    "{$tech->name}'s balance has changed since this settlement — undo the more recent activity first."
                );
            }

            $jobIds = $settlement->settled_job_ids ?? [];

            Job::whereIn('id', $jobIds)->get()->each(function (Job $job) use ($actor) {
                $wasCompleted = $job->status === JobStatus::Completed;

                $job->tech_paid_method = null;
                $job->tech_paid_reference = null;
                $job->tech_paid_at = null;
                $job->tech_paid_recorded_by = null;

                if ($wasCompleted) {
                    $job->status = JobStatus::WorkCompleted->value;
                    $job->completed_at = null;
                }

                $job->save();

                activity()->performedOn($job)->causedBy($actor)
                    ->event('tech_paid_undone')->log('Technician payout undone');
            });

            $tech->cash_balance_cents = $settlement->balance_before_cents;
            $tech->save();

            $settlement->undone_at = now();
            $settlement->undone_by = $actor->id;
            $settlement->save();

            activity()->performedOn($settlement)->causedBy($actor)
                ->event('tech_day_settlement_undone')
                ->log('Tech end-of-day settlement undone for '.$settlement->for_date);

            return $settlement;
        });
    }

    /**
     * Clear the cash a technician owes us (they handed it back). Zeroes the
     * rolling balance and writes a balance_clear audit row. No jobs are touched —
     * job-level pay was already stamped when each day was settled. Returns null
     * when the tech owes nothing.
     */
    public function clearTechCashBalance(
        Employee $tech,
        ?PaymentMethod $method,
        ?string $reference,
        ?string $note,
        Employee $actor,
    ): ?TechSettlement {
        $before = $tech->cashOwedCents();

        if ($before <= 0) {
            return null;
        }

        return DB::transaction(function () use ($tech, $before, $method, $reference, $note, $actor) {
            $tech->cash_balance_cents = 0;
            $tech->save();

            $settlement = TechSettlement::create([
                'technician_id' => $tech->id,
                'type' => 'balance_clear',
                'amount_cents' => $before,
                'direction' => 'collected_from_tech',
                'balance_before_cents' => $before,
                'balance_after_cents' => 0,
                'method' => $method?->value,
                'reference' => $reference,
                'note' => $note,
                'settled_at' => now(),
                'recorded_by' => $actor->id,
            ]);

            activity()->performedOn($settlement)->causedBy($actor)
                ->event('tech_balance_cleared')
                ->log('Tech cash balance cleared');

            return $settlement;
        });
    }

    public function cancel(Job $job, ?string $reason, Employee $actor): Job
    {
        return DB::transaction(function () use ($job, $reason, $actor) {
            $job->status = JobStatus::Canceled->value;
            $job->canceled_at = now();
            $job->cancellation_reason = $reason;
            $job->save();

            $this->outbox->cancelUnsentForJob($job, null, 'job canceled');

            activity()->performedOn($job)->causedBy($actor)
                ->event('canceled')->log('Job canceled');

            Issue::raise(
                IssueType::JobCanceled,
                $job,
                [...$this->jobIssueSnapshot($job, $actor), 'reason' => $reason],
                $actor->id,
            );

            return $job;
        });
    }

    public function markLost(Job $job, ?string $reason, Employee $actor): Job
    {
        return DB::transaction(function () use ($job, $reason, $actor) {
            $job->status = JobStatus::Lost->value;
            $job->canceled_at = now();
            $job->lost_reason = $reason;
            $job->save();

            $this->outbox->cancelUnsentForJob($job, null, 'job lost');

            activity()->performedOn($job)->causedBy($actor)
                ->event('lost')->log('Job marked lost');

            Issue::raise(
                IssueType::JobCanceled,
                $job,
                [...$this->jobIssueSnapshot($job, $actor), 'reason' => $reason],
                $actor->id,
            );

            return $job;
        });
    }

    /**
     * "Uncancel" — reopen a closed (canceled or lost) job and drop it back onto
     * the "needs scheduling" queue, as if it had just been intaken. Clears every
     * scheduling-related fact (date/time, assigned tech, ordered/prepaid parts,
     * reschedule count, overdue alert) and the cancellation stamps, so the job is
     * a clean lead again. Money already recorded (payments) and physical part
     * tracking (a part still out for return) are left untouched — those are facts
     * that happened, not scheduling intentions. No-op on a job that isn't closed.
     */
    public function reopen(Job $job, Employee $actor): Job
    {
        if (! $job->status->isClosed()) {
            return $job;
        }

        return DB::transaction(function () use ($job, $actor) {
            $fromStatus = $job->status?->value;

            // Back to a fresh lead awaiting scheduling.
            $job->status = JobStatus::PendingSchedule->value;

            // Wipe the appointment: date/time, who/when it was scheduled, window
            // prefs, reschedule history, and the overdue-completion arming.
            $job->scheduled_at = null;
            $job->scheduled_by = null;
            $job->last_scheduled_at = null;
            $job->scheduled_exact_time = false;
            $job->scheduled_window_hours = null;
            $job->rescheduled_count = 0;
            $job->overdue_completion_alerted_at = null;

            // Unassign the tech (and clear the fixed cost so the next assignment
            // recomputes it — a different tech may cost a different amount).
            $job->technician_id = null;
            $job->tech_pay_amount_cents = null;

            // Un-stamp any part order/prepay so a re-schedule re-surfaces it on
            // Parts to Order. A part physically out for return is left flagged.
            $job->parts_ordered_at = null;
            $job->parts_ordered_by = null;
            $job->parts_paid_at = null;
            $job->parts_paid_by = null;

            // Clear the closure stamps.
            $job->canceled_at = null;
            $job->cancellation_reason = null;
            $job->lost_reason = null;

            // A reopened job is a clean lead on the scheduling queue, so an old
            // "waiting on the customer" hold does not come back with it.
            $job->scheduling_hold_at = null;
            $job->scheduling_hold_reason = null;
            $job->scheduling_hold_by = null;

            $job->save();

            // Nothing should be queued for a closed job, but make sure the job
            // comes back with a clean outbox — no stale texts fire on reopen.
            $this->outbox->cancelUnsentForJob($job, null, 'job reopened — awaiting scheduling');

            activity()->performedOn($job)->causedBy($actor)
                ->event('reopened')
                ->withProperties(array_filter(['from_status' => $fromStatus]))
                ->log('Job reopened — moved back to needs scheduling');

            return $job->refresh();
        });
    }

    /**
     * A point-in-time snapshot of a job, captured for an Issue's `details` so
     * the review queue (and the record after it's purged) still reads clearly
     * even once the underlying job is gone or has moved on.
     */
    private function jobIssueSnapshot(Job $job, Employee $actor): array
    {
        return [
            'job_number' => $job->job_number,
            'customer_name' => $job->customer_name_snapshot,
            'entity' => $job->entity?->name,
            'quote_cents' => $job->quote_amount_cents,
            'status' => $job->status?->value,
            'scheduled_at' => $job->scheduled_at?->toIso8601String(),
            'technician' => $job->technician?->name,
            'amount_collected_cents' => $job->amount_collected_cents,
            'actor_name' => $actor->name,
        ];
    }

    /**
     * Soft-deletes a job and queues an Issue for review — for "we entered it
     * completely wrong" cases only. Keeps the activity log line and clears out
     * the job's message rows (so nothing ever fires for it), but no longer
     * hard-deletes: the job disappears from every existing query (the
     * SoftDeletes global scope), and permanent purge happens only when a
     * super admin reviews and clears the Issue on the Issues page. Distinct
     * from markLost, which keeps a booked-then-canceled job on record for
     * reporting.
     *
     * A TEST job (Admin > Settings > "Load a test job") is the one exception:
     * nothing real was entered wrong, so there's nothing to review. It's
     * hard-deleted on the spot and never raises an Issue.
     */
    public function deleteCompletely(Job $job, Employee $actor): void
    {
        DB::transaction(function () use ($job, $actor) {
            if ($job->is_test) {
                activity()->performedOn($job)->causedBy($actor)
                    ->event('deleted')->log('Test job deleted');

                $job->scheduledMessages()->delete();
                $job->forceDelete();

                return;
            }

            activity()->performedOn($job)->causedBy($actor)
                ->event('deleted')->log('Job deleted (pending Issues review)');

            $job->scheduledMessages()->delete();

            $snapshot = $this->jobIssueSnapshot($job, $actor);

            $job->delete();

            Issue::raise(IssueType::JobDeleted, $job, $snapshot, $actor->id);
        });
    }

    /**
     * Switch which language a customer is texted in, and make it stick to texts
     * ALREADY waiting in the outbox — not just future ones.
     *
     * Marking someone a Spanish speaker has to change every text they're going
     * to get, including the reminders queued (and rendered) back when the job
     * was scheduled. Returns the customer; the count of rewritten messages is
     * available from the outbox call if a caller wants to report it.
     */
    public function setCustomerLanguage(Customer $customer, Language $language): int
    {
        return DB::transaction(function () use ($customer, $language) {
            if ($customer->preferredLanguage() === $language) {
                return 0;
            }

            $customer->preferred_language = $language->value;
            $customer->save();

            return $this->outbox->rerenderUnsentForCustomer($customer);
        });
    }

    /**
     * Opt a customer out of all automated SMS and cancel their unsent rows
     * across every job. Source distinguishes inbound STOP vs a phoned-in request.
     */
    public function optOutCustomer(Customer $customer, OptOutSource $source, ?string $text = null): Customer
    {
        return DB::transaction(function () use ($customer, $source, $text) {
            $customer->do_not_text = true;
            $customer->sms_consent_status = SmsConsentStatus::OptedOut->value;
            $customer->opted_out_at = now();
            $customer->opt_out_source = $source->value;
            if ($text !== null) {
                $customer->opt_out_text = $text;
            }
            $customer->save();

            $this->outbox->cancelUnsentForCustomer($customer, 'customer opted out');

            // Any review drip mid-flight stops here too (STOP reply / phoned-in).
            app(ReviewDripService::class)
                ->cancelLiveForCustomer($customer, 'customer opted out');

            activity()->performedOn($customer)
                ->event('opted_out')->log('Customer opted out of SMS ('.$source->value.')');

            return $customer;
        });
    }

    /** Closure: work done AND paid AND tech paid → completed. */
    private function maybeComplete(Job $job, ?Employee $actor = null): Job
    {
        if ($job->status === JobStatus::Canceled || $job->status === JobStatus::Lost) {
            return $job;
        }

        if ($job->isWorkComplete() && $job->isFullyPaid() && $job->isTechPaid()) {
            $job->status = JobStatus::Completed->value;
            $job->completed_at = now();
            $job->save();

            activity()->performedOn($job)->causedBy($actor)
                ->event('completed')->log('Job completed');
        }

        return $job;
    }

    private function generateJobNumber(Entity $entity): string
    {
        // Single source of numbering truth lives on the model (also used by the
        // entity-change renumber hook). saveWithUniqueJobNumber retries on the
        // rare collision that slips past the lock.
        return Job::generateNumberFor($entity);
    }

    /**
     * Save a new job, assigning a fresh job number and retrying on a unique
     * collision. The number is derived from the highest existing suffix, which
     * is not row-locked, so two near-simultaneous intakes (or a re-fired
     * Livewire request) can both compute the same next number — the second
     * insert then hits `jobs_job_number_unique`. On that specific violation we
     * regenerate (the loser now sees the winner's row) and retry.
     */
    private function saveWithUniqueJobNumber(Job $job, Entity $entity): void
    {
        $attempts = 0;

        do {
            $job->job_number = $this->generateJobNumber($entity);

            try {
                $job->save();

                return;
            } catch (UniqueConstraintViolationException $e) {
                if (++$attempts >= 5) {
                    throw $e;
                }
            }
        } while (true);
    }
}
