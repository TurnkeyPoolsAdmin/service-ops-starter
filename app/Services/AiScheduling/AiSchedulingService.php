<?php

namespace App\Services\AiScheduling;

use App\Enums\Language;
use App\Enums\TemplateKey;
use App\Jobs\SendSmsJob;
use App\Models\AiSchedulingAttempt;
use App\Models\AiSchedulingSession;
use App\Models\AiSchedulingTurn;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\InboundMessage;
use App\Models\Job;
use App\Models\Setting;
use App\Services\JobLifecycle;
use App\Services\Messaging\OutboxService;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateRenderException;
use App\Services\Telegram\TelegramNotifier;
use App\Support\Timezone;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * The "Schedule w/ AI" negotiation state machine — see
 * docs/ai-scheduling-spec.md §4.2/§4.3.
 *
 * Texts technicians one at a time in priority order proposing a specific
 * slot, reads their replies, pushes back once when a counter-offer doesn't
 * fit what the customer can do, and hands the accepted result to Commit.
 *
 * Two rules shape everything here:
 *  1. The LLM classifies; it never decides. Whether a counter-offer is
 *     acceptable is SchedulingConstraint::admits(), in plain PHP.
 *  2. Anything unexpected — an unclear reply, a question, low confidence, a
 *     refused commit — PAUSES the session for a human rather than guessing.
 */
class AiSchedulingService
{
    /** Opening words of the contact-hours hold note, used to de-dupe it. */
    private const HOLD_NOTE_PREFIX = 'Outside contact hours';

    public function __construct(
        private readonly TechOutreach $outreach,
        private readonly ReplyInterpreter $interpreter,
        private readonly Commit $commit,
        private readonly OutboxService $outbox,
        private readonly TemplateRenderer $renderer,
        private readonly TelegramNotifier $telegram,
        private readonly WarehouseResolver $warehouses,
        private readonly JobLifecycle $lifecycle,
    ) {}

    /** The global kill switch (Admin -> Integrations -> AI assist). */
    public static function enabled(): bool
    {
        return Setting::get('ai_scheduling_enabled', '0') === '1';
    }

    // ---------------------------------------------------------------- start

    /**
     * Open a session for a job and contact the first technician. Returns null
     * when the job already has a live session (one at a time per job).
     *
     * $techOrder is the dispatcher's own contact list (employee ids, in order)
     * from the modal; null leaves the roster ranking in charge. See
     * TechOutreach for what an explicit list does to the ordering.
     *
     * @param  array<int, int|string>|null  $techOrder
     */
    public function start(
        Job $job,
        SchedulingConstraint $constraint,
        Employee $actor,
        string $techMode = AiSchedulingSession::MODE_SIMULATE,
        string $customerMode = AiSchedulingSession::MODE_OFF,
        ?int $warehouseId = null,
        ?string $warehouseOther = null,
        ?array $techOrder = null,
    ): ?AiSchedulingSession {
        if (self::liveSessionFor($job) !== null) {
            return null;
        }

        $session = AiSchedulingSession::create([
            'job_id' => $job->id,
            'status' => AiSchedulingSession::STATUS_RUNNING,
            'constraint' => $constraint->toArray(),
            'original_constraint' => $constraint->toArray(),
            'tech_mode' => $techMode,
            'customer_mode' => $customerMode,
            'tech_order' => $techOrder,
            'warehouse_id' => $warehouseId,
            'warehouse_other' => $warehouseOther,
            'created_by' => $actor->id,
        ]);

        $this->note($session, "Session started by {$actor->name}, targeting ".$constraint->phrase(Language::English));

        if ($order = $session->techOrder()) {
            $names = Employee::whereKey($order)->pluck('name', 'id');

            $this->note($session, 'Contacting in this order: '.collect($order)
                ->map(fn (int $id) => $names[$id] ?? "Employee #{$id}")
                ->implode(', '));
        }

        $this->advance($session);

        return $session->refresh();
    }

    public static function liveSessionFor(Job $job): ?AiSchedulingSession
    {
        return AiSchedulingSession::query()
            ->where('job_id', $job->id)
            ->whereIn('status', [AiSchedulingSession::STATUS_RUNNING, AiSchedulingSession::STATUS_PAUSED])
            ->latest('id')
            ->first();
    }

    // -------------------------------------------------------------- advance

    /**
     * Move the session forward: time out a silent technician, then contact
     * the next one. Safe to call at any time — it returns immediately when
     * the session isn't running, the current technician still has time left
     * to reply, or we're outside contact hours. This is what the
     * every-two-minutes sweep calls.
     *
     * EVERYTHING here is clock-driven, which is exactly what the contact-hours
     * guardrail governs (see outreachAllowedNow): outside 6:00am-10:30pm this
     * holds the session where it stands rather than texting the next tech,
     * writing a silent one off, or booking a slot nobody objected to. Replies
     * come in through handleTechReply() instead and are acted on at any hour.
     */
    public function advance(AiSchedulingSession $session): void
    {
        if ($session->status !== AiSchedulingSession::STATUS_RUNNING) {
            return;
        }

        $current = $session->currentAttempt()->first();

        if ($current !== null && $current->isLive()) {
            if ($current->deadline_at === null || $current->deadline_at->isFuture()) {
                return; // still waiting on them
            }

            if (! self::outreachAllowedNow()) {
                $this->holdForContactHours($session);

                return;
            }

            // A tech who already said yes and just hasn't answered "8am-10am,
            // ok?" is NOT a silent tech — they took the job. Book what we
            // proposed rather than moving on to somebody else.
            if ($current->status === AiSchedulingAttempt::STATUS_CONFIRMING_SLOT) {
                $this->bookProposedSlot($session, $current);

                return;
            }

            $current->update(['status' => AiSchedulingAttempt::STATUS_NO_RESPONSE]);
            $this->note($session, "No reply from {$current->technician?->name} before the deadline; moving on.");
        }

        $max = (int) config('glass.ai_scheduling.max_techs_per_session', 6);
        if ($session->attempts()->count() >= $max) {
            $this->exhaust($session, "reached the {$max}-technician limit without an acceptance");

            return;
        }

        // Checked again here because everything above can be reached with the
        // window open (a decline at 10:29pm), and a fresh cold text to somebody
        // who is asleep is precisely what the guardrail exists to stop.
        if (! self::outreachAllowedNow()) {
            $this->holdForContactHours($session);

            return;
        }

        $tech = $this->outreach->nextTechFor($session);

        if ($tech === null) {
            $this->exhaust($session, 'no eligible technicians left to ask');

            return;
        }

        $this->askTech($session, $tech);
    }

    /**
     * The every-few-minutes sweep. Replies arrive push-style from the inbound
     * webhook, so this only handles what the clock changes: a technician who
     * never answered, a session held overnight outside contact hours, and a
     * session whose appointment date has simply gone by.
     *
     * @return array{advanced: int, expired: int, warehouse_asks_closed: int}
     */
    public function sweep(): array
    {
        $today = Timezone::now()->format('Y-m-d');
        $advanced = 0;
        $expired = 0;

        // Runs first, and outside the running-sessions loop: a warehouse ask
        // hangs off a session that is already `scheduled`.
        $warehouseAsksClosed = $this->closeExpiredWarehouseAsks();

        $sessions = AiSchedulingSession::query()
            ->where('status', AiSchedulingSession::STATUS_RUNNING)
            ->get();

        foreach ($sessions as $session) {
            if ($session->constraintObject()->date < $today) {
                // Expiring supersedes any live attempt, which texts that tech
                // "already filled" — an outbound, so it waits for the window
                // like everything else the clock drives. A session for
                // yesterday expires at 6am rather than 12:01am.
                if (! self::outreachAllowedNow()) {
                    continue;
                }

                $this->expire($session);
                $expired++;

                continue;
            }

            $this->advance($session);
            $advanced++;
        }

        return [
            'advanced' => $advanced,
            'expired' => $expired,
            'warehouse_asks_closed' => $warehouseAsksClosed,
        ];
    }

    /**
     * The day we were negotiating for has passed. Nothing here can succeed
     * any more, so close the session rather than leaving it texting techs
     * about yesterday.
     */
    private function expire(AiSchedulingSession $session): void
    {
        $date = $session->constraintObject()->date;

        $this->supersedeOtherLiveAttempts($session, null);
        $session->update([
            'status' => AiSchedulingSession::STATUS_CANCELED,
            'paused_reason' => "the requested day ({$date}) has passed",
            'current_attempt_id' => null,
        ]);
        $this->note($session, "Expired: the requested day ({$date}) has passed without a technician accepting.");
    }

    /** Create an attempt for this tech and send them the slot offer. */
    private function askTech(AiSchedulingSession $session, Employee $tech): void
    {
        $constraint = $session->constraintObject();
        $language = $tech->preferredLanguage();

        $attempt = AiSchedulingAttempt::create([
            'session_id' => $session->id,
            'employee_id' => $tech->id,
            'position' => $session->attempts()->count() + 1,
            'status' => AiSchedulingAttempt::STATUS_ASKED,
            'load_bucket' => $this->outreach->loadBucketFor($tech, $constraint),
            'asked_at' => now(),
            'deadline_at' => now()->addMinutes(self::responseWindowMinutes($constraint)),
        ]);

        $session->update(['current_attempt_id' => $attempt->id]);

        $this->sendToTech($session, $attempt, $tech, TemplateKey::TechAiSlotOffer, [
            'slot_phrase' => $constraint->phrase($language),
            'load_note' => $this->outreach->loadNoteFor($tech, $constraint, $language),
        ]);
    }

    // --------------------------------------------------------- tech replies

    /**
     * A technician texted us back. Resolves which negotiation they're
     * replying to via the one-live-attempt-per-tech invariant, classifies the
     * reply, and branches. A tech with no live attempt is not ours to handle
     * — except when they're accepting a job that has already been filled, in
     * which case we tell them so rather than leaving them hanging.
     */
    public function handleTechReply(
        Employee $tech,
        InboundMessage $inbound,
        ?AiSchedulingSession $simulationSession = null,
    ): void {
        // Webhooks keep using the one-live-attempt lookup. The typed console
        // passes its selected session so an old attempt cannot steal a reply
        // from a different session for the same technician.
        $attempt = $simulationSession === null
            ? TechOutreach::liveAttemptFor($tech)
            : $simulationSession->attempts()
                ->where('employee_id', $tech->id)
                ->latest('id')
                ->first();

        if ($attempt === null) {
            if ($simulationSession === null) {
                $this->maybeTellTechItIsFilled($tech, $inbound);
            }

            return;
        }

        $session = $attempt->session;

        // The job is already booked and this is their answer to "which
        // warehouse?" — handled BEFORE the running check, because the session
        // is `scheduled` by then and rightly so (see §4.5).
        if ($attempt->status === AiSchedulingAttempt::STATUS_AWAITING_WAREHOUSE) {
            $this->handleWarehouseReply($session, $attempt, $tech, $inbound);

            return;
        }

        if ($session->status !== AiSchedulingSession::STATUS_RUNNING) {
            return;
        }

        $constraint = $session->constraintObject();
        $body = (string) $inbound->body;

        $intent = $this->interpreter->interpretTechReply(
            $body,
            $constraint->phrase(Language::English),
            $this->lastOutboundBody($attempt),
            $this->priorTurnLines($attempt),
            Timezone::now()->format('Y-m-d'),
        );

        $this->recordInbound($session, $attempt, $tech, $inbound, $intent);
        $attempt->update(['last_inbound_at' => now()]);

        if (! $intent->isConfident() || $intent->intent === ReplyIntent::UNCLEAR) {
            $this->escalate($session, $attempt, "could not read {$tech->name}'s reply: \"{$body}\"");

            return;
        }

        // Asked of the raw body rather than read off the intent, so an accept
        // the MODEL classified from a reaction format our keyword pass didn't
        // recognize still gets read back rather than booked outright.
        $viaReaction = ReplyInterpreter::reactionSentiment($body) === ReplyInterpreter::REACTION_POSITIVE;

        match ($intent->intent) {
            ReplyIntent::ACCEPT => $this->acceptOrConfirmSlot($session, $attempt, $tech, null, $viaReaction),
            ReplyIntent::COUNTER => $this->handleCounter($session, $attempt, $tech, $intent),
            ReplyIntent::DECLINE => $this->declineFrom($session, $attempt, $tech),
            ReplyIntent::QUESTION => $this->handleQuestion($session, $attempt, $tech, $intent, $body),
            default => $this->escalate($session, $attempt, "unhandled reply from {$tech->name}"),
        };
    }

    /**
     * A counter-offer. If it satisfies what the customer can actually do we
     * simply take it — that's the whole point of tracking firmness: a
     * customer who said "Tuesday morning" is well served by a tech who says
     * "I can be there at 9". Otherwise we push back on the SAME tech,
     * restating the requirement, because in practice a counter usually means
     * they proposed what suits them without registering the constraint. Only
     * once they've been told and still can't fit it do we move on.
     */
    private function handleCounter(
        AiSchedulingSession $session,
        AiSchedulingAttempt $attempt,
        Employee $tech,
        ReplyIntent $intent,
    ): void {
        $constraint = $session->constraintObject();
        $date = $intent->date ?? $constraint->date;

        if ($constraint->admits($date, $intent->time)) {
            $this->acceptOrConfirmSlot($session, $attempt, $tech, $intent->time);

            return;
        }

        $max = (int) config('glass.ai_scheduling.max_reiterations', 1);

        if ($attempt->reiterations >= $max) {
            $this->note($session, "{$tech->name} could not meet the customer's window after being told; moving on.");
            $this->declineFrom($session, $attempt, $tech);

            return;
        }

        $attempt->update([
            'status' => AiSchedulingAttempt::STATUS_NEGOTIATING,
            'reiterations' => $attempt->reiterations + 1,
            'deadline_at' => now()->addMinutes(self::responseWindowMinutes($constraint)),
        ]);

        $language = $tech->preferredLanguage();

        $this->sendToTech($session, $attempt, $tech, TemplateKey::TechAiSlotReiterate, [
            'constraint_phrase' => $constraint->phrase($language),
            'tech_proposed_time' => (string) ($intent->time ?? ''),
        ]);
    }

    /**
     * A technician asked something instead of answering. BEFORE they have
     * taken the job that is a real escalation — we never improvise at a tech.
     *
     * ⚠️ AFTER they have taken it, it is not. Once the attempt is
     * `confirming_slot` the tech has already accepted and the only thing still
     * open is which two-hour window we write down — and SILENCE at that
     * deadline already books the slot we proposed (see advance()). So
     * escalating on a question made a polite reply strictly WORSE than no
     * reply at all: "Ok, can you check the door at Pilkington?" — an accept
     * plus an unrelated parts question — paused the session and the job never
     * reached the calendar (SFV-2026-0037, 2026-07-31). A question does not
     * retract an acceptance, so §4.5's rule ("once booked, nothing pauses")
     * simply starts one step earlier.
     *
     * More often than not the question IS the warehouse answer, volunteered
     * before we got round to asking for it. Resolving it BEFORE the commit is
     * what lets the whole exchange land in one step: maybeAskWarehouse() then
     * skips itself on its own warehouse_id guard, so we never ask what they
     * just told us, and the job card they get names the real supplier instead
     * of "waiting on you to select warehouse".
     */
    private function handleQuestion(
        AiSchedulingSession $session,
        AiSchedulingAttempt $attempt,
        Employee $tech,
        ReplyIntent $intent,
        string $body,
    ): void {
        $asked = $intent->asks ?? $body;

        if ($attempt->status !== AiSchedulingAttempt::STATUS_CONFIRMING_SLOT) {
            $this->escalate($session, $attempt, "{$tech->name} asked a question: \"{$asked}\"");

            return;
        }

        if ($this->applyVolunteeredWarehouse($session, $tech, $body)) {
            $this->note($session, "{$tech->name} also asked: \"{$asked}\"");
        } else {
            $this->note(
                $session,
                "{$tech->name} asked while confirming the slot: \"{$asked}\" - they had already taken the job, "
                .'so booking the slot we proposed rather than pausing.',
            );
        }

        $this->bookProposedSlot($session, $attempt);
    }

    /**
     * Did that question name a warehouse? Asked only while the job still has
     * no parts source, and it goes through the SAME WarehouseResolver as a
     * real answer to `tech_ai_warehouse_ask` — deterministic scoring first,
     * the model only as a tiebreak, and it picks from the real list rather
     * than writing a name. Null is a perfectly good outcome: the ordinary
     * warehouse ask then runs after the commit, exactly as it does today.
     */
    private function applyVolunteeredWarehouse(
        AiSchedulingSession $session,
        Employee $tech,
        string $body,
    ): bool {
        // Simulate mode resolves before the commit and writes nothing to the
        // job — a "simulation" that picks the parts source is not one.
        if ($session->tech_mode === AiSchedulingSession::MODE_SIMULATE) {
            return false;
        }

        $job = $session->job()->first();

        if ($job === null || $job->isLaborOnly()
            || $job->warehouse_id !== null || filled($job->warehouse_other)) {
            return false;
        }

        $warehouse = $this->warehouses->resolve($body);

        if ($warehouse === null
            || ! $this->lifecycle->setPartsWarehouse($job, $warehouse->id, null, $session->createdBy)) {
            return false;
        }

        $this->note(
            $session,
            "{$tech->name} named {$warehouse->name} while confirming the time; set as the job's parts source.",
        );

        return true;
    }

    /**
     * A tech has agreed — but to WHAT, exactly? Jobs go on the calendar as
     * two-hour arrival windows, so "yes, Monday morning" still leaves a
     * choice, and booking it silently is how a tech ends up surprised by an
     * 8am when they meant 10 (owner, 2026-07-26). So:
     *
     *  - they named a time that fits ("9 to 11") -> that's their answer, book it;
     *  - they AGREED BY REACTION (a thumbs up, a "Liked ...") -> always read
     *    the slot back to them first, even when there is nothing to narrow;
     *  - the customer's window is already as narrow as what we book (an exact
     *    request, or a 2-hour day part) -> nothing left to decide, book it;
     *  - otherwise -> propose the EARLIEST two-hour slot in the window (go
     *    early first) and give them a short window to say otherwise.
     *
     * A bare "yes" to that proposal, or silence through the deadline
     * (see advance()), books what we proposed; a counter naming another time
     * the customer can still do wins outright, which is why it re-enters here
     * with a named time rather than proposing again.
     *
     * The reaction case gets its own copy ("it sounds like you are saying yes
     * to this job...") because a tap is an ambiguous yes in a way a typed one
     * isn't — it might even have been meant as "got it" — so the read-back has
     * to state what we understood, not just which slot we picked.
     */
    private function acceptOrConfirmSlot(
        AiSchedulingSession $session,
        AiSchedulingAttempt $attempt,
        Employee $tech,
        ?string $namedTime,
        bool $viaReaction = false,
    ): void {
        $constraint = $session->constraintObject();

        if ($namedTime !== null) {
            $this->acceptFrom($session, $attempt, $tech, $namedTime);

            return;
        }

        // Already asked; a plain "yes" back — or a thumbs up on the read-back
        // itself — means the proposed slot stands.
        if ($attempt->status === AiSchedulingAttempt::STATUS_CONFIRMING_SLOT) {
            $this->bookProposedSlot($session, $attempt);

            return;
        }

        if (! $constraint->needsSlotNarrowing() && ! $viaReaction) {
            $this->acceptFrom($session, $attempt, $tech, $constraint->preferredTime());

            return;
        }

        $proposed = $constraint->preferredTime();
        $minutes = self::slotConfirmWindowMinutes($constraint);

        $attempt->update([
            'status' => AiSchedulingAttempt::STATUS_CONFIRMING_SLOT,
            'proposed_time' => $proposed,
            'deadline_at' => now()->addMinutes($minutes),
        ]);

        $took = $viaReaction
            ? "{$tech->name} reacted to the offer (thumbs up), reading that as taking "
                .$constraint->phrase(Language::English)
            : "{$tech->name} took ".$constraint->phrase(Language::English);

        $this->note(
            $session,
            $took.'; proposing '.$constraint->bookedPhrase($proposed, Language::English)
            ." and giving them {$minutes} minutes to ask for a different time.",
        );

        $this->sendToTech(
            $session, $attempt, $tech,
            $viaReaction ? TemplateKey::TechAiReactionConfirm : TemplateKey::TechAiSlotConfirm,
            ['slot_phrase' => $constraint->bookedPhrase($proposed, $tech->preferredLanguage())],
        );
    }

    /** Book the slot we proposed — they confirmed it, or their window to object ran out. */
    private function bookProposedSlot(AiSchedulingSession $session, AiSchedulingAttempt $attempt): void
    {
        $tech = $attempt->technician;

        if ($tech === null) {
            $this->escalate($session, $attempt, 'the technician who accepted no longer exists');

            return;
        }

        $constraint = $session->constraintObject();

        $this->acceptFrom(
            $session, $attempt, $tech,
            (string) ($attempt->proposed_time ?: $constraint->preferredTime()),
        );
    }

    private function acceptFrom(
        AiSchedulingSession $session,
        AiSchedulingAttempt $attempt,
        Employee $tech,
        string $agreedTime,
    ): void {
        // ⚠️ Simulate mode stops HERE, before Commit — it does not merely
        // suppress the tech texts. Committing calls JobLifecycle::schedule(),
        // which puts the job on the real calendar AND fires the customer's
        // time-confirmation text plus their reminders. A "simulation" that
        // texts a real customer their appointment is worse than no simulation
        // at all, so the negotiation resolves and reports what it WOULD have
        // booked while the job stays untouched.
        if ($session->tech_mode === AiSchedulingSession::MODE_SIMULATE) {
            $this->simulateAccept($session, $attempt, $tech, $agreedTime);

            return;
        }

        $refusal = $this->commit->execute($session, $attempt, $agreedTime);

        if ($refusal !== null) {
            $this->escalate($session, $attempt, "{$tech->name} accepted but the job could not be scheduled: {$refusal}");

            return;
        }

        $session->refresh();
        $constraint = $session->constraintObject();

        $this->note($session, "{$tech->name} accepted; job scheduled for {$constraint->date} at {$agreedTime}.");

        $bookedPhrase = $constraint->bookedPhrase($agreedTime, Language::English);

        // The ONE Telegram alert AI scheduling sends (owner's call, 2026-07-26):
        // a real job went on the real calendar without a human touching it, so
        // someone should be able to spot-check it. Everything else the session
        // does — expiring, giving up, escalating, re-targeting — is visible on
        // the AI Scheduling page and doesn't need to interrupt the group chat.
        $this->alert(
            "🤖 <b>AI scheduling booked a job</b>\n"
            .'Job '.e($session->job->job_number).' - '.e($tech->name).' on '.e($bookedPhrase).'.'
        );

        // The same full job card a dispatcher-booked job sends (name, phone,
        // address, time, vehicle, part #, warehouse) — it replaced the thin
        // "you're all set for {slot_phrase}" text this used to end on, so the
        // tech gets everything they need to run the job in the one message.
        //
        // Sent from HERE rather than letting JobLifecycle::schedule() do it
        // (Commit passes notifyTech: false) so it lands as a turn in the
        // negotiation transcript and simulate/approve modes still gate it.
        // Its Time line comes from the job, which Commit just scheduled, so it
        // names the CONCRETE slot rather than the customer's whole window.
        $this->sendToTech($session, $attempt, $tech, TemplateKey::TechJobConfirmation, [
            'warehouse' => TemplateRenderer::warehouseVarForTech(
                $session->job->refresh(), $tech->preferredLanguage(),
            ),
        ]);

        // Belt and braces: in strict one-at-a-time operation there is never a
        // second live attempt, but a race (or a retarget mid-flight) could
        // leave one. Close it out rather than leaving a tech expecting a job.
        $this->supersedeOtherLiveAttempts($session, $attempt);

        $this->maybeAskWarehouse($session, $attempt, $tech);
    }

    // ------------------------------------------------------------ warehouse

    /**
     * With the job booked, ask the tech who took it where they want the part
     * ordered from (owner, 2026-07-26). It is their run to the shop, so it is
     * their call — and asking now, in the same thread, beats a dispatcher
     * guessing or chasing them later.
     *
     * Skipped when there is nothing to ask about: a labor-only job (the
     * customer brings the glass), a job whose source was already chosen (the
     * launch modal, or intake), or no active warehouses at all.
     */
    private function maybeAskWarehouse(
        AiSchedulingSession $session,
        AiSchedulingAttempt $attempt,
        Employee $tech,
    ): void {
        $job = $session->job()->first();

        if ($job === null || $job->isLaborOnly()
            || $job->warehouse_id !== null || filled($job->warehouse_other)
            || $this->warehouses->options()->isEmpty()) {
            return;
        }

        $attempt->update([
            'status' => AiSchedulingAttempt::STATUS_AWAITING_WAREHOUSE,
            'deadline_at' => now()->addMinutes(self::warehouseAskWindowMinutes()),
        ]);

        $this->note($session, "Asking {$tech->name} which warehouse to order the part from.");

        $this->sendToTech($session, $attempt, $tech, TemplateKey::TechAiWarehouseAsk, []);
    }

    /**
     * Their answer. Resolved against the REAL warehouse list — deterministic
     * name matching first, the model only as a tiebreaker, and it picks from
     * that same list rather than writing a name (WarehouseResolver).
     *
     * Either way the attempt closes here: this is a courtesy question on an
     * already-booked job, so an answer we can't read costs nothing. The job
     * keeps no parts source and whoever orders the part picks one — the
     * Order-part modal already asks. We never re-ask and never guess.
     */
    private function handleWarehouseReply(
        AiSchedulingSession $session,
        AiSchedulingAttempt $attempt,
        Employee $tech,
        InboundMessage $inbound,
    ): void {
        $body = (string) $inbound->body;
        $warehouse = $this->warehouses->resolve($body, $usedLlm);

        $this->recordWarehouseAnswer($session, $attempt, $tech, $inbound, $warehouse?->name, $usedLlm);
        $attempt->update([
            'status' => AiSchedulingAttempt::STATUS_ACCEPTED,
            'last_inbound_at' => now(),
        ]);

        $job = $session->job()->first();

        if ($warehouse === null || $job === null) {
            $this->note(
                $session,
                "Could not tell which warehouse {$tech->name} meant (\"{$body}\"); leaving the parts source for dispatch.",
            );

            return;
        }

        if (! $this->lifecycle->setPartsWarehouse($job, $warehouse->id, null, $session->createdBy)) {
            $this->note(
                $session,
                "{$tech->name} said {$warehouse->name}, but the job already had a parts source; left it alone.",
            );

            return;
        }

        $this->note($session, "{$tech->name} picked {$warehouse->name}; set as the job's parts source.");

        $this->sendToTech($session, $attempt, $tech, TemplateKey::TechAiWarehouseSet, [
            'warehouse' => $warehouse->name,
        ]);
    }

    /**
     * Close out warehouse questions nobody answered. Runs off the attempt, not
     * the session, because by now the session is `scheduled` and the ordinary
     * sweep no longer looks at it.
     */
    private function closeExpiredWarehouseAsks(): int
    {
        $expired = AiSchedulingAttempt::query()
            ->where('status', AiSchedulingAttempt::STATUS_AWAITING_WAREHOUSE)
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', now())
            ->get();

        foreach ($expired as $attempt) {
            $attempt->update(['status' => AiSchedulingAttempt::STATUS_ACCEPTED]);

            $session = $attempt->session;
            if ($session !== null) {
                $this->note(
                    $session,
                    "No warehouse answer from {$attempt->technician?->name}; leaving the parts source for dispatch.",
                );
            }
        }

        return $expired->count();
    }

    /**
     * The simulated equivalent of a successful commit: the negotiation
     * resolved, but nothing was written to the job and nothing was sent. The
     * distinct `simulated` status is what makes "did this touch a real job?"
     * answerable from one column instead of inferred from tech_mode.
     */
    private function simulateAccept(
        AiSchedulingSession $session,
        AiSchedulingAttempt $attempt,
        Employee $tech,
        string $agreedTime,
    ): void {
        $constraint = $session->constraintObject();
        $instant = Carbon::parse("{$constraint->date} {$agreedTime}", Timezone::display());

        $attempt->update([
            'status' => AiSchedulingAttempt::STATUS_ACCEPTED,
            'agreed_time' => $agreedTime,
        ]);

        $session->update([
            'status' => AiSchedulingSession::STATUS_SIMULATED,
            'resolved_technician_id' => $tech->id,
            'resolved_at' => now(),
            'scheduled_at_utc' => $instant->copy()->utc(),
        ]);

        $this->note(
            $session,
            "SIMULATION: {$tech->name} accepted. Would have assigned them and scheduled the job for "
            ."{$constraint->date} at {$agreedTime}. Nothing was written to the job and no texts were sent."
        );
    }

    private function declineFrom(AiSchedulingSession $session, AiSchedulingAttempt $attempt, Employee $tech): void
    {
        $attempt->update(['status' => AiSchedulingAttempt::STATUS_DECLINED]);
        $this->note($session, "{$tech->name} declined.");

        $this->advance($session->refresh());
    }

    /**
     * A tech accepting a job that has already gone elsewhere deserves an
     * answer. Only fires for a genuine recent attempt of ours, so a random
     * inbound from a technician's phone never triggers a text.
     */
    private function maybeTellTechItIsFilled(Employee $tech, InboundMessage $inbound): void
    {
        $recent = AiSchedulingAttempt::query()
            ->where('employee_id', $tech->id)
            ->whereIn('status', [AiSchedulingAttempt::STATUS_SUPERSEDED, AiSchedulingAttempt::STATUS_NO_RESPONSE])
            ->where('updated_at', '>=', now()->subHours(6))
            ->latest('id')
            ->first();

        if ($recent === null || ReplyInterpreter::fastPath((string) $inbound->body)?->intent !== ReplyIntent::ACCEPT) {
            return;
        }

        // Told once is enough. Now that a thumbs up counts as an accept, a
        // tech acknowledging "this one already went to another tech" with a
        // tapback would otherwise get told again, forever.
        $alreadyTold = $recent->turns()
            ->where('intent', TemplateKey::TechAiFilled->value)
            ->exists();

        if ($alreadyTold) {
            return;
        }

        $session = $recent->session;
        $this->sendToTech($session, $recent, $tech, TemplateKey::TechAiFilled, []);
        $this->note($session, "{$tech->name} accepted late; told them it was already filled.");
    }

    // ----------------------------------------------------- customer replies

    /**
     * The customer texted back while we were lining up a technician. If
     * they're asking for a different day or time we retarget the whole
     * session onto it and start again from the top of the priority list —
     * their appointment is the thing being negotiated, so it outranks any
     * tech conversation in flight. Anything we can't read cleanly, or that
     * isn't about scheduling, pauses for a human: we never improvise at a
     * customer.
     *
     * ...unless `customer_mode = off`, in which case the AI was never going
     * to text this customer at all — dispatch owns that thread. Then a reply
     * we can't act on isn't ours to show: it's dropped entirely (no turn, no
     * note) and the tech negotiation keeps running, instead of burying that
     * negotiation under a conversation the AI isn't part of — let alone
     * pausing the session and paging the group chat over it. Only a reply we
     * actually act on (a confirm, or a new day/time to retarget onto) earns a
     * place on the transcript.
     */
    public function handleCustomerReply(
        Customer $customer,
        InboundMessage $inbound,
        ?AiSchedulingSession $simulationSession = null,
    ): void {
        $session = $simulationSession ?? self::liveSessionForCustomer($customer);

        if ($session === null || $session->status !== AiSchedulingSession::STATUS_RUNNING) {
            return;
        }

        if ($simulationSession !== null
            && $session->job()->where('customer_id', $customer->id)->doesntExist()) {
            return;
        }

        $constraint = $session->constraintObject();
        $body = (string) $inbound->body;

        $intent = $this->interpreter->interpretCustomerReply(
            $body,
            $constraint->phrase(Language::English),
            Timezone::now()->format('Y-m-d'),
        );

        // A "counter" that names neither a date nor a time tells us the
        // customer wants something different but not what — unusable, and
        // guessing at a customer is exactly what we don't do.
        $emptyCounter = $intent->intent === ReplyIntent::COUNTER
            && $intent->date === null
            && $intent->time === null;

        $unreadable = ! $intent->isConfident() || $emptyCounter;
        $actionable = ! $unreadable
            && in_array($intent->intent, [ReplyIntent::ACCEPT, ReplyIntent::COUNTER], true);

        // Customer texting off: keep the transcript to what the AI did about
        // the reply, not the whole thread dispatch is already handling.
        if (! $actionable && $session->customer_mode === AiSchedulingSession::MODE_OFF) {
            return;
        }

        $this->recordInbound($session, null, null, $inbound, $intent, $customer);

        if ($unreadable) {
            $this->customerEscalate($session, "could not read the customer's reply: \"{$body}\"");

            return;
        }

        match ($intent->intent) {
            ReplyIntent::ACCEPT => $this->note($session, 'Customer confirmed the time we are working toward.'),
            ReplyIntent::COUNTER => $this->retarget($session, $intent),
            default => $this->customerEscalate($session, "customer said something that needs a human: \"{$body}\""),
        };
    }

    /**
     * Escalate over something the CUSTOMER said — but only when the AI is
     * actually in that conversation. With `customer_mode = off` a dispatcher
     * is already handling the customer by hand, so we log it and carry on
     * rather than pausing the tech negotiation and alerting the group chat.
     *
     * With texting off, handleCustomerReply drops unreadable replies before
     * they get here, so the note branch is now reached only by a reply we DID
     * act on going wrong — the customer moving the appointment once too often.
     */
    private function customerEscalate(AiSchedulingSession $session, string $reason): void
    {
        if ($session->customer_mode === AiSchedulingSession::MODE_OFF) {
            $this->note($session, 'Customer texting is off, so leaving this to dispatch: '.$reason);

            return;
        }

        $this->escalate($session, null, $reason);
    }

    public static function liveSessionForCustomer(Customer $customer): ?AiSchedulingSession
    {
        return AiSchedulingSession::query()
            ->whereIn('status', [AiSchedulingSession::STATUS_RUNNING, AiSchedulingSession::STATUS_PAUSED])
            ->whereHas('job', fn ($q) => $q->where('customer_id', $customer->id))
            ->latest('id')
            ->first();
    }

    /** Point the session at a new day/time the customer asked for, and start over. */
    private function retarget(AiSchedulingSession $session, ReplyIntent $intent): void
    {
        $max = (int) config('glass.ai_scheduling.max_retargets', 2);

        if ($session->retarget_count >= $max) {
            $this->customerEscalate($session, "customer has changed the time {$max} times; handing over");

            return;
        }

        $old = $session->constraintObject();
        $date = $intent->date ?? $old->date;

        $new = $intent->time !== null
            ? SchedulingConstraint::exact($date, $intent->time)
            : SchedulingConstraint::flexible($date);

        $this->supersedeOtherLiveAttempts($session, null);

        $session->update([
            'constraint' => $new->toArray(),
            'retarget_count' => $session->retarget_count + 1,
            'current_attempt_id' => null,
        ]);

        $this->note(
            $session,
            'Customer moved the appointment: '.$old->phrase(Language::English).' -> '.$new->phrase(Language::English),
        );

        $customer = $session->job->customer;
        if ($customer !== null) {
            $this->sendToCustomer($session, $customer, TemplateKey::CustomerAiTimeCheck, [
                'slot_phrase' => $new->phrase($customer->preferredLanguage()),
            ]);
        }

        $this->advance($session->refresh());
    }

    // ------------------------------------------------------ manual controls

    public function pause(AiSchedulingSession $session, string $reason): void
    {
        $session->update([
            'status' => AiSchedulingSession::STATUS_PAUSED,
            'paused_reason' => $reason,
        ]);
        $this->note($session, "Paused: {$reason}");
    }

    public function resume(AiSchedulingSession $session): void
    {
        if ($session->status !== AiSchedulingSession::STATUS_PAUSED) {
            return;
        }

        $session->update([
            'status' => AiSchedulingSession::STATUS_RUNNING,
            'paused_reason' => null,
        ]);
        $this->note($session, 'Resumed by a dispatcher.');

        $this->advance($session->refresh());
    }

    /** Give up on the current technician immediately and move to the next. */
    public function skipTech(AiSchedulingSession $session, string $reason = 'skipped by a dispatcher'): void
    {
        $current = $session->currentAttempt()->first();

        if ($current !== null && $current->isLive()) {
            $current->update([
                'status' => AiSchedulingAttempt::STATUS_SKIPPED,
                'skip_reason' => $reason,
            ]);
            $this->note($session, "Skipped {$current->technician?->name}: {$reason}");
        }

        $this->advance($session->refresh());
    }

    public function cancel(AiSchedulingSession $session, string $reason = 'canceled by a dispatcher'): void
    {
        $this->supersedeOtherLiveAttempts($session, null);
        $session->update([
            'status' => AiSchedulingSession::STATUS_CANCELED,
            'paused_reason' => $reason,
            'current_attempt_id' => null,
        ]);
        $this->note($session, "Canceled: {$reason}");
    }

    /**
     * Inject a reply as if it had arrived by SMS. This is what makes
     * simulate mode a real test harness rather than a dry run: the entire
     * state machine, classifier included, runs against a typed reply while
     * nothing is sent to anyone.
     */
    public function simulateTechReply(AiSchedulingSession $session, Employee $tech, string $body): void
    {
        // Reject a participant outside the selected session before creating
        // an inbound row. Historical attempts remain selectable in the UI.
        if (! $session->attempts()->where('employee_id', $tech->id)->exists()) {
            return;
        }

        $inbound = InboundMessage::create([
            'employee_id' => $tech->id,
            'from_phone_e164' => (string) $tech->phone,
            'to_phone_e164' => (string) Setting::get('sending_phone_e164', ''),
            'body' => $body,
            'received_at' => now(),
            'processed' => true,
        ]);

        $this->handleTechReply($tech, $inbound, $session);
    }

    /** The customer-side twin of simulateTechReply() — same real state machine, nothing sent. */
    public function simulateCustomerReply(AiSchedulingSession $session, Customer $customer, string $body): void
    {
        if ($session->job()->where('customer_id', $customer->id)->doesntExist()) {
            return;
        }

        $inbound = InboundMessage::create([
            'customer_id' => $customer->id,
            'from_phone_e164' => (string) $customer->phone_e164,
            'to_phone_e164' => (string) Setting::get('sending_phone_e164', ''),
            'body' => $body,
            'received_at' => now(),
            'processed' => true,
        ]);

        $this->handleCustomerReply($customer, $inbound, $session);
    }

    // ---------------------------------------------------------- terminal-ish

    private function exhaust(AiSchedulingSession $session, string $reason): void
    {
        $session->update([
            'status' => AiSchedulingSession::STATUS_EXHAUSTED,
            'paused_reason' => $reason,
            'current_attempt_id' => null,
        ]);
        $this->note($session, "Exhausted: {$reason}");
    }

    /** Stop and hand the session to a human, with the reason on the transcript. */
    private function escalate(AiSchedulingSession $session, ?AiSchedulingAttempt $attempt, string $reason): void
    {
        $attempt?->update(['status' => AiSchedulingAttempt::STATUS_ESCALATED]);

        $session->update([
            'status' => AiSchedulingSession::STATUS_PAUSED,
            'paused_reason' => $reason,
        ]);
        $this->note($session, "Escalated: {$reason}");
    }

    private function supersedeOtherLiveAttempts(AiSchedulingSession $session, ?AiSchedulingAttempt $keep): void
    {
        $others = $session->attempts()
            ->whereIn('status', AiSchedulingAttempt::LIVE_STATUSES)
            ->when($keep !== null, fn ($q) => $q->whereKeyNot($keep->id))
            ->get();

        foreach ($others as $other) {
            $other->update(['status' => AiSchedulingAttempt::STATUS_SUPERSEDED]);

            $tech = $other->technician;
            if ($tech !== null) {
                $this->sendToTech($session, $other, $tech, TemplateKey::TechAiFilled, []);
            }
        }
    }

    // ---------------------------------------------------------------- sends

    /**
     * Compose an outbound to a technician and, depending on the session's
     * mode, send it / park it for approval / record it only. Every path
     * writes a transcript turn, so simulate mode shows exactly the copy a
     * live run would have sent.
     */
    private function sendToTech(
        AiSchedulingSession $session,
        AiSchedulingAttempt $attempt,
        Employee $tech,
        TemplateKey $key,
        array $extraVars,
    ): void {
        $mode = $session->tech_mode;
        $job = $session->job;
        $language = $tech->preferredLanguage();

        try {
            $body = $this->renderer->renderForJob($key, $job, $session->createdBy?->name, $extraVars, $language);
        } catch (TemplateRenderException $e) {
            Log::warning('AI scheduling: could not render tech text', [
                'session_id' => $session->id, 'template' => $key->value, 'error' => $e->getMessage(),
            ]);
            $this->escalate($session, $attempt, "could not build the {$key->label()} text: {$e->getMessage()}");

            return;
        }

        if ($mode === AiSchedulingSession::MODE_SIMULATE) {
            $this->recordOutbound($session, $attempt, $tech, null, $body, $key);

            return;
        }

        if ($mode === AiSchedulingSession::MODE_APPROVE) {
            $this->recordOutbound($session, $attempt, $tech, null, $body, $key, [
                'template' => $key->value,
                'extra_vars' => $extraVars,
            ]);

            return;
        }

        $message = $this->outbox->queueJobMessageForTech(
            $job, $tech, $key, $session->created_by,
            extraVars: $extraVars,
            idempotencyKey: $this->turnIdempotencyKey($session, $attempt, $key),
        );

        if ($message === null) {
            $this->escalate($session, $attempt, "could not text {$tech->name} (no phone or sending number)");

            return;
        }

        SendSmsJob::dispatch($message->id)->afterCommit();

        $this->recordOutbound($session, $attempt, $tech, $message->id, $body, $key);
    }

    private function sendToCustomer(
        AiSchedulingSession $session,
        Customer $customer,
        TemplateKey $key,
        array $extraVars,
    ): void {
        $mode = $session->customer_mode;

        if ($mode === AiSchedulingSession::MODE_OFF) {
            return;
        }

        $job = $session->job;

        try {
            $body = $this->renderer->renderForJob(
                $key, $job, $session->createdBy?->name, $extraVars, $customer->preferredLanguage(),
            );
        } catch (TemplateRenderException $e) {
            Log::warning('AI scheduling: could not render customer text', [
                'session_id' => $session->id, 'template' => $key->value, 'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($mode !== AiSchedulingSession::MODE_LIVE) {
            $this->recordOutbound(
                $session, null, null, null, $body, $key,
                $mode === AiSchedulingSession::MODE_APPROVE ? ['template' => $key->value, 'extra_vars' => $extraVars] : null,
                $customer,
            );

            return;
        }

        $message = $this->outbox->queueForJob(
            $job, $key, null, $session->created_by,
            // queueForJob takes the provider verbatim (unlike queueManual), so
            // pass the key's own preference or this drops off the Quo thread
            // the welcome/dispatch-intro texts started on.
            provider: $key->preferredProvider(),
            idempotencyKey: 'ai:'.$session->id.':cust:'.$session->retarget_count.':tpl:'.$key->value,
            extraVars: $extraVars,
        );

        if ($message !== null) {
            SendSmsJob::dispatch($message->id)->afterCommit();
        }

        $this->recordOutbound($session, null, null, $message?->id, $body, $key, null, $customer);
    }

    /**
     * Actually send an outbound that was parked for approval. Called from the
     * session panel's Send button (phase 7).
     */
    public function approveOutbound(AiSchedulingTurn $turn): void
    {
        if (! $turn->isAwaitingApproval()) {
            return;
        }

        $session = $turn->session;
        $payload = $turn->pending_payload ?? [];
        $key = TemplateKey::tryFrom($payload['template'] ?? '');

        if ($key === null) {
            $turn->update(['approval_status' => AiSchedulingTurn::APPROVAL_SKIPPED]);

            return;
        }

        $extraVars = $payload['extra_vars'] ?? [];

        if ($turn->employee_id !== null) {
            $message = $this->outbox->queueJobMessageForTech(
                $session->job, $turn->technician, $key, $session->created_by,
                extraVars: $extraVars,
                idempotencyKey: 'ai:turn:'.$turn->id,
            );
        } else {
            $message = $this->outbox->queueForJob(
                $session->job, $key, null, $session->created_by,
                provider: $key->preferredProvider(),
                idempotencyKey: 'ai:turn:'.$turn->id,
                extraVars: $extraVars,
            );
        }

        if ($message !== null) {
            SendSmsJob::dispatch($message->id)->afterCommit();
        }

        $turn->update([
            'approval_status' => AiSchedulingTurn::APPROVAL_APPROVED,
            'scheduled_message_id' => $message?->id,
        ]);
    }

    // --------------------------------------------------------- transcript

    private function recordOutbound(
        AiSchedulingSession $session,
        ?AiSchedulingAttempt $attempt,
        ?Employee $tech,
        ?int $scheduledMessageId,
        string $body,
        TemplateKey $key,
        ?array $pendingPayload = null,
        ?Customer $customer = null,
    ): void {
        AiSchedulingTurn::create([
            'session_id' => $session->id,
            'attempt_id' => $attempt?->id,
            'party' => $tech !== null ? AiSchedulingTurn::PARTY_TECH : AiSchedulingTurn::PARTY_CUSTOMER,
            'direction' => AiSchedulingTurn::DIRECTION_OUT,
            'employee_id' => $tech?->id,
            'customer_id' => $customer?->id,
            'scheduled_message_id' => $scheduledMessageId,
            'body' => $body,
            'intent' => $key->value,
            'approval_status' => $pendingPayload !== null ? AiSchedulingTurn::APPROVAL_PENDING : null,
            'pending_payload' => $pendingPayload,
            'created_at' => now(),
        ]);
    }

    private function recordInbound(
        AiSchedulingSession $session,
        ?AiSchedulingAttempt $attempt,
        ?Employee $tech,
        InboundMessage $inbound,
        ReplyIntent $intent,
        ?Customer $customer = null,
    ): void {
        AiSchedulingTurn::create([
            'session_id' => $session->id,
            'attempt_id' => $attempt?->id,
            'party' => $tech !== null ? AiSchedulingTurn::PARTY_TECH : AiSchedulingTurn::PARTY_CUSTOMER,
            'direction' => AiSchedulingTurn::DIRECTION_IN,
            'employee_id' => $tech?->id,
            'customer_id' => $customer?->id,
            'inbound_message_id' => $inbound->id,
            'body' => $inbound->body,
            'intent' => $intent->intent,
            'confidence' => $intent->confidence,
            'classification' => [
                'intent' => $intent->intent,
                'date' => $intent->date,
                'time' => $intent->time,
                'asks' => $intent->asks,
                'reason' => $intent->reason,
            ],
            'model' => $intent->usedLlm ? config('glass.ai_scheduling.model') : null,
            'used_llm' => $intent->usedLlm,
            'created_at' => now(),
        ]);
    }

    /**
     * A warehouse answer isn't an accept/counter/decline, so it gets its own
     * turn rather than being forced into ReplyIntent's vocabulary. Same
     * transcript shape as any other inbound: what they said, what we made of
     * it, and whether reading it cost an API call.
     */
    private function recordWarehouseAnswer(
        AiSchedulingSession $session,
        AiSchedulingAttempt $attempt,
        Employee $tech,
        InboundMessage $inbound,
        ?string $warehouseName,
        bool $usedLlm,
    ): void {
        AiSchedulingTurn::create([
            'session_id' => $session->id,
            'attempt_id' => $attempt->id,
            'party' => AiSchedulingTurn::PARTY_TECH,
            'direction' => AiSchedulingTurn::DIRECTION_IN,
            'employee_id' => $tech->id,
            'inbound_message_id' => $inbound->id,
            'body' => $inbound->body,
            'intent' => 'warehouse',
            'classification' => ['warehouse' => $warehouseName, 'matched' => $warehouseName !== null],
            'model' => $usedLlm ? config('glass.ai_scheduling.model') : null,
            'used_llm' => $usedLlm,
            'created_at' => now(),
        ]);
    }

    private function note(AiSchedulingSession $session, string $body): void
    {
        AiSchedulingTurn::create([
            'session_id' => $session->id,
            'party' => AiSchedulingTurn::PARTY_SYSTEM,
            'direction' => AiSchedulingTurn::DIRECTION_NOTE,
            'body' => $body,
            'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------- helpers

    /** @return array<int, string> prior turns of this attempt, oldest first, for classifier context. */
    private function priorTurnLines(AiSchedulingAttempt $attempt): array
    {
        return $attempt->turns()
            ->whereIn('direction', [AiSchedulingTurn::DIRECTION_OUT, AiSchedulingTurn::DIRECTION_IN])
            ->get()
            ->map(fn (AiSchedulingTurn $t) => ($t->direction === AiSchedulingTurn::DIRECTION_OUT ? 'We said: ' : 'They said: ')
                .str_replace("\n", ' ', (string) $t->body))
            ->all();
    }

    private function lastOutboundBody(AiSchedulingAttempt $attempt): string
    {
        return (string) $attempt->turns()
            ->where('direction', AiSchedulingTurn::DIRECTION_OUT)
            ->latest('id')
            ->value('body');
    }

    private function turnIdempotencyKey(AiSchedulingSession $session, AiSchedulingAttempt $attempt, TemplateKey $key): string
    {
        $seq = $attempt->turns()->where('direction', AiSchedulingTurn::DIRECTION_OUT)->count();

        return "ai:{$session->id}:{$attempt->id}:{$seq}:tpl:{$key->value}";
    }

    /**
     * THE CONTACT-HOURS GUARDRAIL (owner, 2026-07-27): the clock may only act
     * between 6:00am and 10:30pm Pacific. Outside it the sweep holds — no cold
     * text to the next technician, no writing a silent one off, no booking a
     * slot on silence, no expiring a stale session out from under a live
     * attempt.
     *
     * ⚠️ It gates the CLOCK, not the conversation. A technician who texts us
     * at 11pm gets answered at 11pm — handleTechReply() and everything it
     * triggers (a reiterate, a slot read-back, the booking itself, the
     * warehouse question) deliberately do not consult this. "Unless a response
     * IS sent after 10:30pm, then you can engage" is the owner's wording, and
     * silence is the only thing that waits for morning.
     *
     * An earlier version of this gate was removed on 2026-07-26 for parking a
     * live 8:50pm session with no explanation; hence the 10:30pm end and the
     * transcript note (holdForContactHours) rather than a silent return.
     */
    public static function outreachAllowedNow(?CarbonInterface $localNow = null): bool
    {
        $now = ($localNow ?? Timezone::now())->format('H:i');
        $from = (string) config('glass.ai_scheduling.active_hours.from', '06:00');
        $until = (string) config('glass.ai_scheduling.active_hours.until', '22:30');

        // Zero-padded HH:MM compares correctly as a string. A window configured
        // to wrap midnight (until < from) is the complement of a closed one, so
        // the test flips from AND to OR.
        return $from <= $until
            ? ($now >= $from && $now < $until)
            : ($now >= $from || $now < $until);
    }

    /** The next instant contact hours open, for a session held overnight. */
    public static function nextContactWindowOpensAt(?CarbonInterface $localNow = null): Carbon
    {
        $now = $localNow
            ? Carbon::parse($localNow->format('Y-m-d H:i:s'), Timezone::display())
            : Timezone::now();

        [$h, $m] = array_map(
            'intval',
            explode(':', (string) config('glass.ai_scheduling.active_hours.from', '06:00')),
        );

        $today = $now->copy()->setTime($h, $m);

        return $now->lt($today) ? $today : $today->addDay();
    }

    /**
     * Say on the transcript that we're waiting for morning, once per hold. The
     * sweep re-enters advance() every two minutes all night, so without the
     * de-dupe a session held at 11pm would carry 200 identical notes by
     * daybreak — and a hold nobody can see is what got the previous version of
     * this guardrail deleted.
     */
    private function holdForContactHours(AiSchedulingSession $session): void
    {
        // reorder() first: the relation is ordered created_at ASC, which would
        // otherwise hand back the session's OLDEST turn and de-dupe nothing.
        $last = (string) $session->turns()->reorder()->latest('id')->value('body');

        if (str_starts_with($last, self::HOLD_NOTE_PREFIX)) {
            return;
        }

        $from = (string) config('glass.ai_scheduling.active_hours.from', '06:00');
        $until = (string) config('glass.ai_scheduling.active_hours.until', '22:30');

        $this->note(
            $session,
            self::HOLD_NOTE_PREFIX." ({$from}-{$until} Pacific), so holding until "
            .self::nextContactWindowOpensAt()->format('D M j, g:ia')
            .' before texting another technician. A reply from the technician we already asked is still acted on right away.',
        );
    }

    /**
     * How long a technician gets to reply before we move on: 90 minutes. A job
     * being scheduled for today gets a much shorter fuse — there simply isn't
     * time to wait an hour and a half per tech.
     */
    private static function responseWindowMinutes(SchedulingConstraint $constraint): int
    {
        $isToday = $constraint->date === Timezone::now()->format('Y-m-d');

        return (int) ($isToday
            ? config('glass.ai_scheduling.same_day_response_window_minutes', 10)
            : config('glass.ai_scheduling.response_window_minutes', 90));
    }

    /**
     * How long a tech gets to push back on the slot we proposed before we book
     * it: twenty minutes. Never longer than the window they had to answer the
     * offer in the first place — a same-day job that only waits 10 minutes for
     * a "yes" can't then sit 20 more waiting on a slot they already agreed to.
     */
    private static function slotConfirmWindowMinutes(SchedulingConstraint $constraint): int
    {
        return min(
            (int) config('glass.ai_scheduling.slot_confirm_window_minutes', 20),
            self::responseWindowMinutes($constraint),
        );
    }

    /** How long a booked tech has to say which warehouse before we stop waiting. */
    private static function warehouseAskWindowMinutes(): int
    {
        return (int) config('glass.ai_scheduling.warehouse_ask_window_minutes', 30);
    }

    /** Best-effort group-chat alert; silent when Telegram isn't configured or is switched off. */
    private function alert(string $message): void
    {
        try {
            if (Setting::get('telegram_alerts_enabled', '1') === '0' || ! $this->telegram->configured()) {
                return;
            }

            $this->telegram->send($message);
        } catch (\Throwable $e) {
            Log::warning('AI scheduling: Telegram alert failed', ['error' => $e->getMessage()]);
        }
    }
}
