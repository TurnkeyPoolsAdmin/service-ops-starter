<?php

namespace App\Services\AiScheduling;

use App\Models\AiSchedulingAttempt;
use App\Models\AiSchedulingSession;
use App\Models\Employee;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;

/**
 * Decides WHICH technician an AI scheduling session talks to next, and in
 * what order. See docs/ai-scheduling-spec.md §1.2.
 *
 * Ordering is strict priority (Employee::scopeOrderByPriority) in two passes:
 * every tech who is open or lightly booked for the requested part of day
 * first, then the genuinely busy ones. That honors "one at a time by
 * priority" without burning the #1 slot on someone who already has two jobs
 * that morning — while still asking them rather than writing them off, since
 * the offer text tells them what they already have and lets them judge.
 *
 * UNLESS the dispatcher set their own order on the modal
 * (AiSchedulingSession::techOrder). Then that list wins outright — both its
 * order and its membership — and the busy-to-the-back pass is skipped: they
 * were looking at this job when they dragged the names, so second-guessing
 * them with a load heuristic would just make the list they see lie.
 */
final class TechOutreach
{
    public function __construct(private readonly TechAvailability $availability) {}

    /**
     * The next technician to contact for this session, or null when the pool
     * is exhausted. Excludes, in order of how disqualifying they are:
     *  - techs not opted in / inactive / without a usable phone
     *  - techs with a genuine exact-time conflict at the requested slot
     *  - techs already worked through in THIS session (unless superseded — a
     *    retarget onto a new day makes a previous "no" irrelevant)
     *  - techs mid-negotiation on ANY other session (the invariant below)
     */
    public function nextTechFor(AiSchedulingSession $session): ?Employee
    {
        return $this->candidatesFor($session)->first();
    }

    /** @return Collection<int, Employee> ordered exactly as they would be contacted. */
    public function candidatesFor(AiSchedulingSession $session): Collection
    {
        $constraint = $session->constraintObject();
        $job = $session->job;

        $poolIds = $job->entity?->techPoolEntityIds() ?? array_filter([$job->entity_id]);

        $spentIds = $this->spentEmployeeIds($session);
        $busyElsewhereIds = self::employeesMidNegotiation($session->id);
        $chosenIds = $session->techOrder();

        $candidates = Employee::query()
            ->active()
            ->withJobFunction('technician')
            ->where('ai_scheduling_enabled', true)
            ->whereIn('entity_id', $poolIds)
            // A dispatcher-picked list is an allow-list, not just a sort.
            ->when($chosenIds !== null, fn ($query) => $query->whereKey($chosenIds))
            ->whereKeyNot([...$spentIds, ...$busyElsewhereIds])
            ->orderByPriority()
            ->get()
            ->reject(fn (Employee $tech) => blank(PhoneNumber::toE164($tech->phone)))
            ->reject(fn (Employee $tech) => $this->availability->hasHardConflict($tech, $constraint));

        if ($chosenIds !== null) {
            return $candidates
                ->sortBy(fn (Employee $tech) => array_search($tech->id, $chosenIds, true))
                ->values();
        }

        return $candidates
            // Stable two-pass sort: busy techs move to the back but keep their
            // priority order within each group (sortBy is stable in PHP 8+).
            ->sortBy(fn (Employee $tech) => $this->isBusy($tech, $constraint) ? 1 : 0)
            ->values();
    }

    /** open | light | busy for this tech at the session's requested slot. */
    public function loadBucketFor(Employee $tech, SchedulingConstraint $constraint): string
    {
        return $this->availability->loadFor($tech, $constraint->date)->bucket($constraint);
    }

    public function loadNoteFor(Employee $tech, SchedulingConstraint $constraint, $language): string
    {
        return $this->availability->loadFor($tech, $constraint->date)->noteFor($constraint, $language);
    }

    /**
     * THE INVARIANT: a technician is mid-negotiation on at most one job at a
     * time, across every session. Without it, an inbound text from a phone
     * number could not be attributed to a job with certainty — and a
     * misattributed "yes" schedules the wrong tech onto the wrong customer.
     * A session that wants a tech who is already talking to us simply skips
     * them (they become eligible again once that attempt closes).
     *
     * @return array<int, int> employee ids to exclude
     */
    public static function employeesMidNegotiation(?int $exceptSessionId = null): array
    {
        return AiSchedulingAttempt::query()
            ->whereIn('status', AiSchedulingAttempt::LIVE_STATUSES)
            ->when($exceptSessionId !== null, fn ($q) => $q->where('session_id', '!=', $exceptSessionId))
            ->pluck('employee_id')
            ->unique()
            ->all();
    }

    /**
     * The single live attempt for a technician, or null. This is how an
     * inbound reply is correlated back to a job (see phase 6's inbound
     * routing) — safe precisely because of the invariant above.
     */
    public static function liveAttemptFor(Employee $tech): ?AiSchedulingAttempt
    {
        return AiSchedulingAttempt::query()
            ->where('employee_id', $tech->id)
            ->whereIn('status', AiSchedulingAttempt::LIVE_STATUSES)
            ->latest('id')
            ->first();
    }

    /**
     * Techs this session has already worked through and shouldn't re-ask.
     * SUPERSEDED attempts are deliberately NOT counted: they only happen when
     * the customer moved the appointment, and a tech who couldn't do Tuesday
     * morning may well be free Wednesday afternoon.
     *
     * @return array<int, int>
     */
    private function spentEmployeeIds(AiSchedulingSession $session): array
    {
        return $session->attempts()
            ->where('status', '!=', AiSchedulingAttempt::STATUS_SUPERSEDED)
            ->pluck('employee_id')
            ->unique()
            ->all();
    }

    private function isBusy(Employee $tech, SchedulingConstraint $constraint): bool
    {
        return $this->loadBucketFor($tech, $constraint) === TechDayLoad::BUSY;
    }
}
