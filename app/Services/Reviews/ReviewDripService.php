<?php

namespace App\Services\Reviews;

use App\Enums\MessageType;
use App\Enums\TemplateKey;
use App\Jobs\SendSmsJob;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Job;
use App\Models\ReviewRequest;
use App\Services\Messaging\OutboxService;
use App\Support\Timezone;
use Carbon\CarbonInterface;

/**
 * The call-first review flow, owner of every ReviewRequest transition:
 *
 *   work marked complete (or, for a calibration job, calibration confirmed)
 *     -> AWAITING_CALL opens; dispatch is prompted to call the customer now
 *   dispatch logs the first call:
 *     connected/no-answer + thumbs up   -> text 1 now (review_connected if the
 *                                          call connected, else review_request_1)
 *                                       -> text 2 next day (cron)
 *                                       -> CALL NEEDED (manual, dashboard — the
 *                                          second call)
 *     connected/no-answer + thumbs down -> skipped, nothing ever sends
 *     "skip for later"                  -> stays awaiting_call (Telegram nags)
 *   7pm deadline passes with no call logged (or "skip for later" chosen)
 *     -> auto-falls-through: thumbs up, text 1 = review_request_1, same flow
 *   call connected -> "send review text"      -> after-call text now
 *                  -> "not interested"        -> canceled
 *   call voicemail                            -> template 3 now
 *   after-call / template 3 sent              -> final text 1 week later (cron)
 *   review spotted (manual, any time)         -> thank-you text, drip ends
 *   customer texts STOP                       -> canceled (via optOutCustomer)
 *
 * Sends go through OutboxService::queueForJob - idempotent per job+template, so
 * no step can ever double-send - and are dispatched immediately. A blocked send
 * (opted out / lead paused / review URL missing) leaves the step un-stamped;
 * the cron retries it on next_send_at until it queues or the drip is stopped.
 *
 * The scheduled steps (text 2 the next day, the final text a week later) are
 * rolled forward off Sundays and tagged holidays via skipClosedDays(), so an
 * automated ask never lands on a day the shop is closed.
 */
class ReviewDripService
{
    public function __construct(private OutboxService $outbox) {}

    /**
     * Open the call-first queue for a job: creates the awaiting_call row and
     * nothing else - no sentiment is known yet, no text goes out. Called from
     * JobLifecycle::markWorkComplete (non-calibration jobs) and, for a
     * calibration job, from CalibrationService::confirm once ops confirms.
     * Idempotent per job - a job can only ever carry one ReviewRequest.
     */
    public function startAwaitingCall(Job $job, Employee $actor): ReviewRequest
    {
        $existing = ReviewRequest::query()->where('job_id', $job->id)->first();
        if ($existing) {
            return $existing;
        }

        $request = ReviewRequest::create([
            'job_id' => $job->id,
            'customer_id' => $job->customer_id,
            'entity_id' => $job->entity_id,
            'status' => ReviewRequest::STATUS_AWAITING_CALL,
            'created_by' => $actor->id,
        ]);

        activity()->performedOn($job)->causedBy($actor)
            ->event('review_awaiting_call_started')
            ->log('Review call-first queue opened - call the customer now');

        return $request;
    }

    /**
     * Record the first call (right after completion). "Skip for later" leaves
     * the row awaiting_call (it keeps nagging via Telegram until logged or the
     * 7pm deadline auto-falls-through). Connected/no-answer both require the
     * thumbs up/down: down skips the drip entirely (nothing ever sends); up
     * starts it - text 1 is review_connected when the call actually connected,
     * else the plain review_request_1 (see advance()).
     */
    public function logFirstCall(ReviewRequest $request, string $outcome, ?bool $wentWell, Employee $actor): void
    {
        if ($outcome === 'skip') {
            $request->first_call_skipped_at = now();
            $request->save();

            activity()->performedOn($request->job)->causedBy($actor)
                ->event('review_first_call_skipped')
                ->log('First review call skipped for later');

            return;
        }

        $request->first_call_outcome = $outcome;
        $request->first_call_logged_at = now();
        $request->first_call_logged_by = $actor->id;
        $request->sentiment = $wentWell ? 'up' : 'down';
        $request->status = $wentWell ? ReviewRequest::STATUS_ACTIVE : ReviewRequest::STATUS_SKIPPED;
        $request->save();

        activity()->performedOn($request->job)->causedBy($actor)
            ->event($wentWell ? 'review_first_call_logged_up' : 'review_first_call_logged_down')
            ->log('First review call logged ('.$outcome.') - '.($wentWell ? 'went well' : 'not great'));

        if (! $wentWell) {
            return;
        }

        $request->next_send_at = now();
        $request->save();
        $this->advance($request);
    }

    /**
     * 7pm deadline sweep: every awaiting_call row past its deadline (whether
     * "skip for later" was chosen or the call was simply never logged) falls
     * through into the regular flow - thumbs up, text 1 = review_request_1
     * (never review_connected; nobody confirmed a call happened). Called from
     * reviews:advance, before advanceDue(), so it lands within minutes of 7pm.
     */
    public function autoStartOverdueFirstCalls(): int
    {
        $rows = ReviewRequest::query()->awaitingFirstCall()->with('job.customer')->get()
            ->filter(fn (ReviewRequest $r) => $r->isFirstCallOverdue());

        foreach ($rows as $request) {
            $request->first_call_outcome = ReviewRequest::FIRST_CALL_AUTO;
            $request->sentiment = 'up';
            $request->status = ReviewRequest::STATUS_ACTIVE;
            $request->next_send_at = now();
            $request->save();

            activity()->performedOn($request->job)
                ->event('review_first_call_auto_fallthrough')
                ->log('First-call deadline passed - review drip started automatically');

            $this->advance($request);
        }

        return $rows->count();
    }

    /** Cron entry point: push every due active drip one step forward. */
    public function advanceDue(): int
    {
        $due = ReviewRequest::query()->dueForAdvance()->with('job.customer')->get();

        foreach ($due as $request) {
            $this->advance($request);
        }

        return $due->count();
    }

    /**
     * Send whatever automated step is due for one active request. Exactly one
     * step per call; the next step gets its own next_send_at. A blocked send
     * keeps next_send_at in the past so the cron simply retries later. Only
     * ever acts on ACTIVE rows - awaiting_call rows have no next_send_at and
     * fail this guard, so they can never be advanced before their first call.
     */
    public function advance(ReviewRequest $request): void
    {
        if ($request->status !== ReviewRequest::STATUS_ACTIVE) {
            return;
        }
        if ($request->next_send_at === null || $request->next_send_at->isFuture()) {
            return;
        }

        $customer = $request->job?->customer;
        if ($customer === null) {
            $this->cancel($request, 'job or customer no longer exists');

            return;
        }
        if ($customer->do_not_text) {
            $this->cancel($request, 'customer opted out');

            return;
        }

        // Text 1 - immediately after the thumbs-up (either from the first-call
        // log or the 7pm auto-fallthrough). A first call that actually
        // connected gets the warmer review_connected body; everything else
        // (no answer, auto-fallthrough) gets the plain review_request_1.
        if ($request->step1_sent_at === null) {
            $key = $request->first_call_outcome === ReviewRequest::FIRST_CALL_CONNECTED
                ? TemplateKey::ReviewConnected
                : TemplateKey::ReviewRequest1;

            if ($this->send($request, $key)) {
                $request->step1_template = $key->value;
                $request->step1_sent_at = now();
                $request->next_send_at = $this->step2SendAtUtc();
                $request->save();
            }

            return;
        }

        // Text 2 — the next-day follow-up; the manual-call gate then opens
        // call_after_days later (see ReviewRequest::isCallNeeded).
        if ($request->step2_sent_at === null) {
            if ($this->send($request, TemplateKey::ReviewRequest2)) {
                $request->step2_sent_at = now();
                $request->next_send_at = null;
                $request->save();
            }

            return;
        }

        // Text 3 — after-call or voicemail text that logCall couldn't queue
        // (e.g. lead was paused at the time); retry until it goes out.
        if ($request->call_outcome !== null && $request->step3_sent_at === null) {
            $key = $request->call_outcome === ReviewRequest::OUTCOME_VOICEMAIL
                ? TemplateKey::ReviewVoicemail
                : TemplateKey::ReviewAfterCall;

            if ($this->send($request, $key)) {
                $request->step3_template = $key->value;
                $request->step3_sent_at = now();
                $request->next_send_at = $this->finalSendAtUtc();
                $request->save();
            }

            return;
        }

        // Final text — one week after text 3; the drip is done.
        if ($request->step3_sent_at !== null && $request->final_sent_at === null) {
            if ($this->send($request, TemplateKey::ReviewFinal)) {
                $request->final_sent_at = now();
                $request->next_send_at = null;
                $request->status = ReviewRequest::STATUS_COMPLETED;
                $request->save();
            }

            return;
        }

        // Nothing left to send — clear the trigger so the cron stops picking it up.
        $request->next_send_at = null;
        $request->save();
    }

    /**
     * Record the manual call after text 2 (the SECOND call). Voicemail →
     * template 3 goes out now. Connected → dispatch chose either "send the
     * after-call text" or "not interested" (which removes the customer from
     * the queue).
     */
    public function logCall(ReviewRequest $request, string $outcome, ?string $connectedChoice, Employee $actor): void
    {
        $request->call_outcome = $outcome;
        $request->call_logged_at = now();
        $request->call_logged_by = $actor->id;
        $request->save();

        activity()->performedOn($request->job)->causedBy($actor)
            ->event('review_call_logged')
            ->log('Review call logged: '.($outcome === ReviewRequest::OUTCOME_VOICEMAIL
                ? 'voicemail'
                : 'connected — '.($connectedChoice === 'not_interested' ? 'not interested' : 'send review text')));

        if ($outcome === ReviewRequest::OUTCOME_CONNECTED && $connectedChoice === 'not_interested') {
            $this->cancel($request, 'not interested (said on call)', $actor);

            return;
        }

        // Voicemail, or connected + "send review text": text 3 goes out now.
        $request->next_send_at = now();
        $request->save();
        $this->advance($request);
    }

    /**
     * We spotted their review (manual check) — thank them and end the drip.
     * Allowed from any state except an already-thanked one; pending sends are
     * canceled so the customer never gets asked again after reviewing.
     */
    public function markReviewLeft(ReviewRequest $request, Employee $actor): void
    {
        $this->outbox->cancelUnsentForJob($request->job, [MessageType::Review->value], 'review left');

        $thanked = $this->send($request, TemplateKey::ReviewThankYou);

        $request->review_left_at = now();
        $request->thank_you_sent_at = $thanked ? now() : null;
        $request->status = ReviewRequest::STATUS_REVIEW_LEFT;
        $request->next_send_at = null;
        $request->save();

        activity()->performedOn($request->job)->causedBy($actor)
            ->event('review_left')
            ->log('Review spotted — thank-you text '.($thanked ? 'sent' : 'could not be sent'));
    }

    /** Hold the drip where it is; resume() picks up exactly there. */
    public function pause(ReviewRequest $request, Employee $actor): void
    {
        if ($request->status !== ReviewRequest::STATUS_ACTIVE) {
            return;
        }

        $this->outbox->cancelUnsentForJob($request->job, [MessageType::Review->value], 'review drip paused');

        $request->status = ReviewRequest::STATUS_PAUSED;
        $request->paused_at = now();
        $request->save();

        activity()->performedOn($request->job)->causedBy($actor)
            ->event('review_drip_paused')->log('Review drip paused');
    }

    /** Resume a paused drip; an overdue step fires on the next cron tick. */
    public function resume(ReviewRequest $request, Employee $actor): void
    {
        if ($request->status !== ReviewRequest::STATUS_PAUSED) {
            return;
        }

        $request->status = ReviewRequest::STATUS_ACTIVE;
        $request->paused_at = null;
        $request->save();

        activity()->performedOn($request->job)->causedBy($actor)
            ->event('review_drip_resumed')->log('Review drip resumed');
    }

    /** Stop the drip for good (STOP reply, "not interested", manual cancel). */
    public function cancel(ReviewRequest $request, string $reason, ?Employee $actor = null): void
    {
        if (in_array($request->status, [
            ReviewRequest::STATUS_CANCELED,
            ReviewRequest::STATUS_COMPLETED,
            ReviewRequest::STATUS_REVIEW_LEFT,
        ], true)) {
            return;
        }

        if ($request->job) {
            $this->outbox->cancelUnsentForJob($request->job, [MessageType::Review->value], 'review drip canceled');
        }

        $request->status = ReviewRequest::STATUS_CANCELED;
        $request->canceled_at = now();
        $request->cancel_reason = $reason;
        $request->next_send_at = null;
        $request->save();

        $log = activity()->performedOn($request->job)->event('review_drip_canceled');
        if ($actor) {
            $log->causedBy($actor);
        }
        $log->log('Review drip canceled — '.$reason);
    }

    /** Kill every live drip for a customer (inbound STOP / phoned-in opt-out). */
    public function cancelLiveForCustomer(Customer $customer, string $reason): void
    {
        ReviewRequest::query()
            ->where('customer_id', $customer->id)
            ->live()
            ->get()
            ->each(fn (ReviewRequest $request) => $this->cancel($request, $reason));
    }

    /**
     * Queue one drip text and dispatch it immediately. queueForJob is
     * idempotent per job+template, so a retried step can never double-send.
     * Returns false when blocked (opted out, lead paused, review URL missing).
     */
    private function send(ReviewRequest $request, TemplateKey $key): bool
    {
        $message = $this->outbox->queueForJob($request->job, $key, null, $request->created_by);

        if ($message === null) {
            return false;
        }

        SendSmsJob::dispatch($message->id)->afterCommit();

        return true;
    }

    /**
     * Text 2 goes out the NEXT local day at the configured wall-clock time,
     * rolled forward off Sundays and holidays so no ask lands on a closed day.
     */
    private function step2SendAtUtc(): CarbonInterface
    {
        return Timezone::localDateTimeToUtc(
            $this->skipClosedDays(Timezone::now()->addDay()),
            (string) config('glass.reviews.followup_local_time', '10:00'),
        );
    }

    /**
     * The final text follows text 3 by the configured number of days, again
     * skipping Sundays and holidays. Keeps the current local wall-clock time.
     */
    private function finalSendAtUtc(): CarbonInterface
    {
        $target = Timezone::now()->addDays((int) config('glass.reviews.final_after_days', 7));

        return $this->skipClosedDays($target)->utc();
    }

    /**
     * Advance a local send moment forward, one day at a time, until it lands on
     * a day the shop is open — i.e. not a Sunday and not a tagged holiday (the
     * same holiday rules that drive the dispatch-intro variants). Only the
     * calendar date moves; the wall-clock time is preserved.
     */
    private function skipClosedDays(CarbonInterface $local): CarbonInterface
    {
        while ($local->isSunday() || Holiday::isHoliday($local)) {
            $local = $local->copy()->addDay();
        }

        return $local;
    }
}
