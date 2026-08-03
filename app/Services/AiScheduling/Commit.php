<?php

namespace App\Services\AiScheduling;

use App\Enums\JobStatus;
use App\Models\AiSchedulingAttempt;
use App\Models\AiSchedulingSession;
use App\Services\JobLifecycle;
use App\Support\Timezone;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place an AI scheduling session writes to a Job. Everything upstream
 * of this — the model, the negotiation, the transcript — is reversible; this
 * is not, so it re-validates the whole world immediately before committing
 * and refuses rather than guessing. See docs/ai-scheduling-spec.md §4.4.
 *
 * The check that matters most is #2: minutes can pass between texting a tech
 * and reading their "yes", and in that window a dispatcher may well have
 * scheduled the job by hand. Overwriting them would be silent, rare, and
 * exactly the kind of thing nobody would think to look for — so a job that
 * already has a time or a technician stops this dead.
 */
final class Commit
{
    public function __construct(
        private readonly JobLifecycle $lifecycle,
        private readonly TechAvailability $availability,
    ) {}

    /**
     * Attempt the commit. Returns null on success, or a short human-readable
     * reason the caller should escalate with. Never throws for a failed
     * precondition — a refusal is a normal outcome here, not an error.
     *
     * $agreedTime is 'H:i' local, already validated against the constraint by
     * the caller (SchedulingConstraint::admits) — this method re-derives the
     * instant from it rather than taking a UTC timestamp, so there is no way
     * for a timezone mistake upstream to slip a job onto the wrong hour.
     */
    public function execute(
        AiSchedulingSession $session,
        AiSchedulingAttempt $attempt,
        string $agreedTime,
    ): ?string {
        $session->refresh();
        $job = $session->job()->first();
        $tech = $attempt->technician()->first();
        $constraint = $session->constraintObject();

        if ($session->status !== AiSchedulingSession::STATUS_RUNNING) {
            return "session is {$session->status}, not running";
        }

        if ($job === null) {
            return 'job no longer exists';
        }

        // A human scheduled it while we were negotiating. Never overwrite.
        if ($job->scheduled_at !== null) {
            return 'job already has a scheduled time (scheduled outside this session)';
        }
        if ($job->technician_id !== null) {
            return 'job already has a technician assigned (assigned outside this session)';
        }

        if ($job->status !== JobStatus::PendingSchedule) {
            return "job status is {$job->status->value}, no longer awaiting scheduling";
        }

        if ($tech === null || ! $tech->active) {
            return 'technician is no longer active';
        }
        if (! $tech->ai_scheduling_enabled) {
            return 'technician opted out of AI scheduling';
        }

        if ($this->availability->hasHardConflict($tech, $constraint)) {
            return 'technician now has a conflicting job at that time';
        }

        $instant = Carbon::parse("{$constraint->date} {$agreedTime}", Timezone::display());

        if ($instant->isPast()) {
            return 'the agreed time is in the past';
        }
        if ($agreedTime < $constraint->earliest || $agreedTime > $constraint->latest) {
            return "the agreed time {$agreedTime} falls outside the customer's window";
        }

        $exactTime = $constraint->firmness === SchedulingConstraint::FIRM_EXACT;

        DB::transaction(function () use ($session, $attempt, $job, $tech, $instant, $exactTime, $agreedTime) {
            $this->lifecycle->schedule(
                job: $job,
                scheduledAtUtc: $instant->copy()->utc(),
                // The dispatcher who launched the session, so the activity log
                // and jobs.scheduled_by name a real person rather than "system".
                actor: $session->createdBy,
                technicianId: $tech->id,
                warehouseId: $session->warehouse_id,
                warehouseOther: $session->warehouse_other,
                exactTime: $exactTime,
                // null = fall back to the configured arrival window; an exact
                // request ignores it entirely (see JobLifecycle::schedule).
                windowHours: null,
                // The tech's job card is sent by the session instead
                // (AiSchedulingService::acceptFrom), so it joins the
                // negotiation transcript and obeys simulate/approve mode.
                // Without this the tech would get it twice.
                notifyTech: false,
            );

            $attempt->update([
                'status' => AiSchedulingAttempt::STATUS_ACCEPTED,
                'agreed_time' => $agreedTime,
            ]);

            $session->update([
                'status' => AiSchedulingSession::STATUS_SCHEDULED,
                'resolved_technician_id' => $tech->id,
                'resolved_at' => now(),
                'scheduled_at_utc' => $instant->copy()->utc(),
                'current_attempt_id' => $attempt->id,
            ]);

            activity()->performedOn($job)->causedBy($session->createdBy)
                ->event('ai_scheduled')
                ->log("AI scheduling assigned {$tech->name} and scheduled the job");
        });

        return null;
    }
}
