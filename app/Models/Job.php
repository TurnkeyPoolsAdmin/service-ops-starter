<?php

namespace App\Models;

use App\Enums\BankMatchKind;
use App\Enums\CalibrationStatus;
use App\Enums\JobStatus;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\ParseStatus;
use App\Enums\PaymentMethod;
use App\Enums\TimePreference;
use App\Enums\TransactionMatchStatus;
use App\Support\BusinessDays;
use App\Support\GlassPosition;
use App\Support\Name;
use App\Support\PartNumberGlass;
use App\Support\Timezone;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Job extends Model
{
    use LogsActivity;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'parse_status' => ParseStatus::class,
            'payment_method' => PaymentMethod::class,
            'payment_preference' => PaymentMethod::class,
            'tech_paid_method' => PaymentMethod::class,
            'requested_time_preference' => TimePreference::class,
            'part_available' => 'boolean',
            'labor_only' => 'boolean',
            'part_cost_cents' => 'integer',
            'quote_amount_cents' => 'integer',
            'is_insurance' => 'boolean',
            'is_test' => 'boolean',
            'needs_calibration' => 'boolean',
            'is_multi_day' => 'boolean',
            'multi_day_first_completed_at' => 'datetime',
            'calibration_status' => CalibrationStatus::class,
            'calibration_scheduled_at' => 'datetime',
            'calibration_completed_at' => 'datetime',
            'calibration_confirmed_at' => 'datetime',
            'calibration_went_well' => 'boolean',
            'insurance_amount_cents' => 'integer',
            'insurance_expected_cents' => 'integer',
            'amount_collected_cents' => 'integer',
            'stripe_invoice_amount_cents' => 'integer',
            'stripe_invoice_created_at' => 'datetime',
            'stripe_paid_at' => 'datetime',
            'tech_pay_amount_cents' => 'integer',
            'additional_labor_cents' => 'integer',
            'tech_parts_cost_cents' => 'integer',
            'rescheduled_count' => 'integer',
            'parts_json' => 'array',
            'parsed_payload_json' => 'array',
            'parse_warnings_json' => 'array',
            'scheduled_at' => 'datetime',
            'scheduled_exact_time' => 'boolean',
            'scheduled_window_hours' => 'integer',
            'last_scheduled_at' => 'datetime',
            'work_completed_at' => 'datetime',
            'overdue_completion_alerted_at' => 'datetime',
            'completed_at' => 'datetime',
            'canceled_at' => 'datetime',
            'payment_received_at' => 'datetime',
            'payment_confirmed_at' => 'datetime',
            'xpro_collected_at' => 'datetime',
            'xpro_overdue_alerted_at' => 'datetime',
            'insurance_paid_at' => 'datetime',
            'tech_paid_at' => 'datetime',
            'parts_ordered_at' => 'datetime',
            'parts_paid_at' => 'datetime',
            'scheduling_hold_at' => 'datetime',
            'part_glass_override_at' => 'datetime',
            'part_return_needed_at' => 'datetime',
            'part_returned_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'status', 'scheduled_at', 'scheduled_exact_time', 'scheduled_window_hours', 'technician_id', 'warehouse_id',
                'work_completed_at', 'amount_collected_cents', 'payment_method', 'payment_received_at',
                'payment_confirmed_at', 'xpro_collected_at',
                'tech_pay_amount_cents', 'additional_labor_cents', 'additional_labor_reason',
                'tech_parts_cost_cents',
                'tech_paid_at', 'canceled_at',
                'is_insurance', 'needs_calibration', 'labor_only', 'insurance_amount_cents', 'insurance_paid_at',
                'insurance_carrier', 'insurance_claim_number', 'insurance_expected_cents',
                'is_multi_day', 'multi_day_first_completed_at',
                'calibration_status', 'calibration_scheduled_at', 'calibration_completed_at',
                'calibration_confirmed_at', 'calibration_went_well',
                'cancellation_reason', 'lost_reason',
                'parts_ordered_at', 'parts_paid_at',
                'scheduling_hold_at', 'scheduling_hold_reason',
                'part_return_needed_at', 'part_returned_at',
                'part_glass_override_at',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    // ---- Derived values ----

    /** Gross margin in cents: quote - part cost - tech pay - additional labor. */
    public function getGrossMarginCentsAttribute(): int
    {
        return (int) ($this->quote_amount_cents ?? 0)
            - (int) ($this->part_cost_cents ?? 0)
            - (int) ($this->tech_pay_amount_cents ?? 0)
            - (int) ($this->additional_labor_cents ?? 0);
    }

    /**
     * Total the tech is actually owed: base tech pay + any additional labor.
     * Used to pre-fill the payout dialogs so a tech with extra labor gets paid
     * the full amount, not just their fixed cost. Once the payout is recorded,
     * markTechPaid folds the additional labor into tech_pay_amount_cents, so this
     * keeps returning the same total afterward (additional_labor_cents is 0 then).
     */
    public function getExpectedTechPayCentsAttribute(): int
    {
        return (int) ($this->tech_pay_amount_cents ?? 0)
            + (int) ($this->additional_labor_cents ?? 0);
    }

    /**
     * Parts the tech fronted out of pocket that we reimburse them, on top of
     * labor. 0 in the normal case (we bought the parts). Kept separate from
     * tech_pay_amount_cents so it never leaks into gross margin / ROAS — the part
     * cost is already counted via part_cost_cents; this is the same cost merely
     * flowing through the tech.
     */
    public function getTechPartsReimbursementCentsAttribute(): int
    {
        return (int) ($this->tech_parts_cost_cents ?? 0);
    }

    /**
     * Everything the tech is owed for this job: labor (base + additional) plus any
     * parts they paid for. Drives the End-of-Day payout total and its breakdown.
     */
    public function getTotalOwedToTechCentsAttribute(): int
    {
        return $this->expected_tech_pay_cents + $this->tech_parts_reimbursement_cents;
    }

    public function isPaid(): bool
    {
        return $this->payment_received_at !== null;
    }

    /**
     * How much of the quote is still owed after everything collected so far.
     * Zero when nothing is quoted (no balance to chase) or once fully collected.
     */
    public function balanceDueCents(): int
    {
        $quote = (int) ($this->quote_amount_cents ?? 0);
        if ($quote <= 0) {
            return 0;
        }

        return max(0, $quote - (int) ($this->amount_collected_cents ?? 0));
    }

    /** A payment has been recorded but it doesn't yet cover the full quote. */
    public function isPartiallyPaid(): bool
    {
        return $this->isPaid() && $this->balanceDueCents() > 0;
    }

    /**
     * The quote has been collected in full (or there's a payment on a job with no
     * quote). This — not isPaid() — is what gates job completion: a deposit alone
     * leaves a balance due and must not auto-complete the job.
     */
    public function isFullyPaid(): bool
    {
        return $this->isPaid() && $this->balanceDueCents() === 0;
    }

    public function isTechPaid(): bool
    {
        return $this->tech_paid_at !== null;
    }

    /**
     * The customer supplies their own glass — we only install it. There is no
     * part for us to order, prepay, source from a warehouse or cost out, so the
     * entire parts side of the job is skipped (queues, alerts, Issues, forms).
     * Everything else — scheduling, texts, tech pay, payment, reviews — is normal.
     */
    public function isLaborOnly(): bool
    {
        return (bool) $this->labor_only;
    }

    public function isPartsOrdered(): bool
    {
        return $this->parts_ordered_at !== null;
    }

    /**
     * Every part label on the job, primary first — parts_json when it exists,
     * otherwise the legacy single part_number.
     *
     * @return array<int, string>
     */
    public function partLabels(): array
    {
        $labels = collect($this->parts_json ?? [])
            ->map(fn ($p) => trim((string) ($p['label'] ?? '')))
            ->filter()
            ->values()
            ->all();

        if ($labels === []) {
            $labels = array_filter([trim((string) ($this->part_number ?? ''))]);
        }

        return array_values($labels);
    }

    /**
     * "The part number says one glass, the position says another" — DERIVED from
     * part_number/parts_json + glass_position, never stored (see
     * App\Support\PartNumberGlass), so correcting either value clears it by
     * itself. Null when they agree, when either side is unreadable, or on a
     * labor-only job (the customer's own glass, no part number of ours to check).
     *
     * @return array{part_code: string, part_label: string, part_number: string, position: string, position_code: string}|null
     */
    public function partGlassMismatch(): ?array
    {
        if ($this->isLaborOnly()) {
            return null;
        }

        return PartNumberGlass::mismatch($this->partLabels(), $this->glass_position);
    }

    public function hasPartGlassMismatch(): bool
    {
        return $this->partGlassMismatch() !== null;
    }

    /**
     * A mismatch nobody has answered for yet: the part and the position still
     * disagree AND no override covers THIS exact pairing. Scheduling prompts on
     * it; an override (with a note) satisfies it and raises a manager Issue.
     */
    public function hasUnresolvedPartGlassMismatch(): bool
    {
        $mismatch = $this->partGlassMismatch();

        if ($mismatch === null) {
            return false;
        }

        return $this->part_glass_override_at === null
            || $this->part_glass_override_signature !== PartNumberGlass::signature($mismatch);
    }

    /** An override was used and the part/position still disagree — a manager should look. */
    public function hasOverriddenPartGlassMismatch(): bool
    {
        $mismatch = $this->partGlassMismatch();

        return $mismatch !== null
            && $this->part_glass_override_at !== null
            && $this->part_glass_override_signature === PartNumberGlass::signature($mismatch);
    }

    public function isPartsPaid(): bool
    {
        return $this->parts_paid_at !== null;
    }

    /**
     * The tech had already picked up the part when the appointment was canceled,
     * so they were asked to return it. True from cancel until it's marked back in.
     */
    public function needsPartReturn(): bool
    {
        return $this->part_return_needed_at !== null && $this->part_returned_at === null;
    }

    public function isPartReturned(): bool
    {
        return $this->part_returned_at !== null;
    }

    /**
     * The parts come from an "Other" supplier — a dealer / one-off place not in
     * our warehouse list (free-text warehouse_other, no warehouse_id). We have no
     * account there, so the part has to be prepaid before it's released.
     */
    public function usesOtherWarehouse(): bool
    {
        return $this->warehouse_id === null && filled($this->warehouse_other);
    }

    /**
     * An "Other"-supplier job whose part we still have to prepay. Non-"Other"
     * jobs (our warehouses) never need this — we're on account there.
     */
    public function needsPartsPrepaid(): bool
    {
        return ! $this->isLaborOnly() && $this->usesOtherWarehouse() && ! $this->isPartsPaid();
    }

    /** Insurance carrier has paid its share (separate from the customer deductible). */
    public function isInsurancePaid(): bool
    {
        return $this->insurance_paid_at !== null;
    }

    /**
     * What the carrier still owes us on this claim: the expected payout minus
     * whatever actually landed. 0 once the carrier is paid (the actual payout is
     * then the truth, whatever we expected) and 0 while no expected amount has
     * been entered — we can't claim a receivable we never quantified. Never
     * negative: the carrier paying MORE than expected isn't money owed to us.
     */
    public function insuranceOutstandingCents(): int
    {
        if (! $this->is_insurance || $this->isInsurancePaid()) {
            return 0;
        }

        return max(0, (int) ($this->insurance_expected_cents ?? 0) - (int) ($this->insurance_amount_cents ?? 0));
    }

    /**
     * Does this job's windshield need ADAS calibration? The flag is optional at
     * intake, but an insurance job ALWAYS needs it — provided the glass is a
     * WINDSHIELD. Only a windshield carries the ADAS camera, so an insurance
     * door/quarter/back glass job needs nothing (2026-07-19). The effective
     * answer is the stored flag OR (is_insurance AND windshield) — never
     * store-forced, always derived here.
     */
    public function needsCalibration(): bool
    {
        return $this->needs_calibration || ($this->is_insurance && $this->isWindshield());
    }

    /**
     * Multi-day install, first visit still ahead of us: the NEXT "mark work
     * complete" finishes day one only, and must be followed by scheduling the
     * second visit (see JobLifecycle::completeFirstInstallDay).
     */
    public function needsSecondVisitScheduled(): bool
    {
        return $this->is_multi_day
            && $this->multi_day_first_completed_at === null
            && $this->work_completed_at === null;
    }

    /**
     * Multi-day install, day one done and the job back in the scheduling loop
     * (or already booked for its second visit). Scheduling it now texts the
     * customer the "next part of your service" note rather than a first-time
     * confirmation, and completing it runs the NORMAL completion flow.
     */
    public function isAwaitingSecondVisit(): bool
    {
        return $this->is_multi_day
            && $this->multi_day_first_completed_at !== null
            && $this->work_completed_at === null;
    }

    /** Is the glass on this job a windshield? (Tolerates "WS" / free text.) */
    public function isWindshield(): bool
    {
        return GlassPosition::isWindshield($this->glass_position);
    }

    /**
     * Job has entered (or finished) the post-install calibration partner pipeline.
     * Distinct from needsCalibration() — a job can need cal but not be in the
     * pipeline yet (install not done).
     */
    public function isInCalibrationPipeline(): bool
    {
        return $this->calibration_status !== null;
    }

    /** Ops has thumbs-up/down confirmed after the partner finished. */
    public function isCalibrationConfirmed(): bool
    {
        return $this->calibration_confirmed_at !== null;
    }

    /**
     * The review call-first queue must wait until calibration is confirmed.
     * True while the job needs cal and that confirmation hasn't happened yet.
     */
    public function reviewDeferredForCalibration(): bool
    {
        return $this->needsCalibration() && ! $this->isCalibrationConfirmed();
    }

    /** A first review call is waiting to be made (or auto-fallen-through). */
    public function needsReviewCall(): bool
    {
        return $this->reviewRequest?->status === ReviewRequest::STATUS_AWAITING_CALL;
    }

    /** Year / make / model (or raw vehicle string) for display and partner alerts. */
    public function vehicleLabel(): string
    {
        $label = trim(implode(' ', array_filter([
            $this->vehicle_year,
            $this->vehicle_make,
            $this->vehicle_model,
        ])));

        return $label !== '' ? $label : (string) ($this->vehicle_raw ?? '');
    }

    public function isWorkComplete(): bool
    {
        return $this->work_completed_at !== null;
    }

    /**
     * Scheduled, not yet marked work-complete, and the arrival window closed a
     * grace period ago — mirrors OverdueCompletionAlert::dueJobs() per-instance
     * (no alerted_at gate; that's the Telegram alert's own dedup, unrelated to
     * whether the task is still open). Drives the Open Tasks dashboard.
     */
    public function isOverdueForCompletion(): bool
    {
        if ($this->status !== JobStatus::Scheduled || $this->work_completed_at !== null || $this->scheduled_at === null) {
            return false;
        }

        $grace = (int) config('glass.telegram.overdue_grace_hours', 2);

        return now()->gte($this->scheduledWindowEndUtc()->copy()->addHours($grace));
    }

    /**
     * Every "Open Tasks" dashboard task-type key currently open for this job.
     * Single source of truth for that page — it unions per-type scope queries
     * to filter the DB, but always redraws each row's badges from here so they
     * can never drift from what actually put the job on the page.
     */
    public function openTaskTypes(): array
    {
        $types = [];

        // A job on hold is waiting on the customer, not on us — it drops off the
        // open-tasks board exactly as it drops out of scopeNeedsScheduling.
        if ($this->status === JobStatus::PendingSchedule && ! $this->isOnSchedulingHold()) {
            $types[] = 'scheduling';
        }

        if ($this->isOverdueForCompletion()) {
            $types[] = 'completion';
        }

        if ($this->isWorkComplete() && ! $this->isFullyPaid()) {
            $types[] = 'payment';
        }

        // Both parts tasks are moot on a labor-only job — the customer brings the
        // glass, so there's nothing to order or prepay (mirrors the scopes).
        if (! $this->isLaborOnly() && $this->status === JobStatus::Scheduled && $this->scheduled_at !== null && $this->parts_ordered_at === null) {
            $types[] = 'part_ordered';
        }

        if (! $this->isLaborOnly() && ! $this->status->isClosed() && $this->usesOtherWarehouse() && $this->isPartsOrdered() && ! $this->isPartsPaid()) {
            $types[] = 'part_paid';
        }

        if ($this->calibration_status !== null && $this->calibration_confirmed_at === null) {
            $types[] = 'calibration';
        }

        if ($this->needsReviewCall()) {
            $types[] = 'review_call';
        }

        return $types;
    }

    /** Jobs whose review call-first queue is still waiting on the first call. */
    public function scopeNeedsReviewCall(Builder $query): Builder
    {
        return $query->whereHas('reviewRequest', fn (Builder $q) => $q->where('status', ReviewRequest::STATUS_AWAITING_CALL));
    }

    /** Jobs waiting on the calibration partner (needed or scheduled). */
    public function scopeCalibrationOpen(Builder $query): Builder
    {
        return $query->whereIn('calibration_status', [
            CalibrationStatus::Needed->value,
            CalibrationStatus::Scheduled->value,
        ]);
    }

    /** Partner finished; ops still needs to thumbs-up/down. */
    public function scopeCalibrationAwaitingConfirm(Builder $query): Builder
    {
        return $query
            ->where('calibration_status', CalibrationStatus::Completed->value)
            ->whereNull('calibration_confirmed_at');
    }

    /** Any job in the calibration section (pipeline started). */
    public function scopeInCalibrationPipeline(Builder $query): Builder
    {
        return $query->whereNotNull('calibration_status');
    }

    /**
     * End of the arrival window (UTC): scheduled_at + the job's window length
     * (its scheduled_window_hours override, else the configured default). An
     * exact-time job has no window, so its "end" is the appointment instant
     * itself. Null when the job isn't scheduled. Drives the overdue-completion
     * Telegram alert (fires this + a grace period after the window closes).
     */
    public function scheduledWindowEndUtc(): ?CarbonInterface
    {
        if ($this->scheduled_at === null) {
            return null;
        }

        $hours = $this->scheduled_exact_time
            ? 0
            : ($this->scheduled_window_hours ?: (int) config('glass.appointment_window_hours', 2));

        return $this->scheduled_at->copy()->addHours($hours);
    }

    /**
     * Paid via an Xpro-collected method (Klarna / SwiftSwipe / Other - Xpro Team)
     * and we haven't yet collected that money back from the Xpro team. Drives the
     * Owed from Xpro finance page + the weekly Friday Telegram alert.
     */
    public function isOwedFromXpro(): bool
    {
        return $this->xpro_collected_at === null && $this->xproOwedCents() > 0;
    }

    /**
     * How much of what's been collected on this job came in via an Xpro-collected
     * method (Klarna / SwiftSwipe / Other - Xpro Team) — summed over the payment
     * LINES, since a job can be part-paid by Xpro and part by us. Zero once we've
     * stamped the Xpro money collected back. This — not the summary
     * amount_collected_cents — is what "Owed from Xpro" chases.
     */
    public function xproOwedCents(): int
    {
        if ($this->xpro_collected_at !== null) {
            return 0;
        }

        return (int) $this->payments
            ->filter(fn (JobPayment $p) => $p->method?->isXproCollected() ?? false)
            ->sum('amount_cents');
    }

    /**
     * When the Xpro clock started for this job — the earliest Xpro-method payment
     * line's collection time (falling back to the job's first payment time). Null
     * when nothing was collected via an Xpro method. Drives the collection deadline
     * below.
     */
    public function xproOwedSince(): ?Carbon
    {
        $line = $this->payments
            ->filter(fn (JobPayment $p) => $p->method?->isXproCollected() ?? false)
            ->sortBy('collected_at')
            ->first();

        return $line?->collected_at ?? $this->payment_received_at;
    }

    /**
     * The moment (Pacific NOON) by which the Xpro team should have handed the
     * money back: 12pm the WEDNESDAY after the first Friday on/after the day we
     * recorded the Xpro payment. That Friday is when the money first shows on the
     * weekly Owed-from-Xpro digest; they then have through the weekend + Mon/Tue
     * to settle, and come that Wednesday noon it's overdue.
     *
     * Example: Xpro payment recorded Mon Jul 13 -> first Friday Jul 17 -> due
     * Wed Jul 22 at 12:00pm PT (overdue from Jul 22 noon onward).
     *
     * Returned as a display-tz Carbon; null when the job carries no Xpro payment.
     */
    public function xproCollectionDueAt(): ?Carbon
    {
        $since = $this->xproOwedSince();

        if ($since === null) {
            return null;
        }

        $day = Timezone::toDisplay($since)->startOfDay();

        // First Friday on/after the collection day (a Friday counts as itself).
        while ($day->dayOfWeek !== Carbon::FRIDAY) {
            $day->addDay();
        }

        // The following Wednesday = that Friday + 5 days, at 12pm noon PT.
        return $day->addDays(5)->setTime(12, 0);
    }

    /**
     * Still owed from Xpro AND past the collection deadline above — the condition
     * that raises an Issue and fires the escalation Telegram alert.
     */
    public function isXproCollectionOverdue(): bool
    {
        if (! $this->isOwedFromXpro()) {
            return false;
        }

        $due = $this->xproCollectionDueAt();

        return $due !== null && Timezone::now()->gte($due);
    }

    /** Human label of the distinct Xpro method(s) this job was part-paid with. */
    public function xproMethodsLabel(): ?string
    {
        $labels = $this->payments
            ->filter(fn (JobPayment $p) => $p->method?->isXproCollected() ?? false)
            ->map(fn (JobPayment $p) => $p->method->label())
            ->unique()
            ->values();

        return $labels->isEmpty() ? null : $labels->implode(', ');
    }

    /**
     * At least one confirmed Chase transaction is linked to this job as a customer
     * payment (regardless of whether the amounts reconcile). Uses the eager-loaded
     * bankMatches when present so table columns don't N+1.
     */
    public function hasMatchedTransaction(): bool
    {
        return $this->bankMatches
            ->contains(fn (BankTransactionMatch $m) => $m->kind === BankMatchKind::JobPayment
                && $m->bankTransaction?->match_status === BankTransaction::STATUS_MATCHED);
    }

    /** Deposit-positive cents summed over this job's confirmed customer-payment transactions. */
    public function matchedTransactionCents(): int
    {
        return (int) $this->bankMatches
            ->filter(fn (BankTransactionMatch $m) => $m->kind === BankMatchKind::JobPayment
                && $m->bankTransaction?->match_status === BankTransaction::STATUS_MATCHED)
            ->sum(fn (BankTransactionMatch $m) => -1 * (int) $m->bankTransaction->amount_cents);
    }

    /**
     * Where the recorded non-cash payment stands against the bank ledger. Null for
     * cash or unpaid jobs (nothing to match). A matched transaction whose amount(s)
     * reconcile to the collected total → Confirmed; matched but off → AmountMismatch;
     * paid with nothing matched yet → AwaitingMatch (a manual admin confirm also
     * counts as Confirmed, even without a bank row).
     */
    public function transactionMatchStatus(): ?TransactionMatchStatus
    {
        if (! $this->isPaid() || $this->payment_method === PaymentMethod::Cash) {
            return null;
        }

        if ($this->hasMatchedTransaction()) {
            return $this->transactionAmountReconciles()
                ? TransactionMatchStatus::Confirmed
                : TransactionMatchStatus::AmountMismatch;
        }

        if ($this->payment_confirmed_at !== null) {
            return TransactionMatchStatus::Confirmed;
        }

        return TransactionMatchStatus::AwaitingMatch;
    }

    /** Matched transaction(s) sum to the collected amount, within the ± tolerance. */
    public function transactionAmountReconciles(): bool
    {
        $tolerance = (int) config('glass.bank_matching.amount_tolerance_cents', 500);

        return abs($this->matchedTransactionCents() - (int) $this->amount_collected_cents) <= $tolerance;
    }

    /**
     * A Chase (bank) transaction has been matched to this job's customer payment
     * AND the amounts reconcile — i.e. we've verified the right money landed. An
     * amount mismatch does NOT count as confirmed; it stays in AR for review.
     */
    public function hasBankConfirmedPayment(): bool
    {
        return $this->hasMatchedTransaction() && $this->transactionAmountReconciles();
    }

    /**
     * Have we verified the customer's payment actually landed? Cash is always
     * "confirmed" (the tech collected it in person — out of AR scope); otherwise
     * either a matched Chase transaction OR a manual admin confirmation counts.
     */
    public function isPaymentReceiptConfirmed(): bool
    {
        if (! $this->isPaid()) {
            return false;
        }

        return $this->payment_method === PaymentMethod::Cash
            || $this->payment_confirmed_at !== null
            || $this->hasBankConfirmedPayment();
    }

    /** The board/infolist display name follows the same casing rule as Customer. */
    protected function customerNameSnapshot(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Name::normalizeCase($value));
    }

    protected static function booted(): void
    {
        // Keep job_number consistent with the job's entity. If a dispatcher
        // re-files a job to a different location (e.g. an LA lead that turns out
        // to be Del Mar), renumber it to the new entity's "CODE-YEAR-####" so an
        // "LA-…" number never lingers on a Del Mar job — which is what orphaned a
        // number and collided the intake counter. Only renumber an already-
        // numbered job whose entity actually changed; brand-new jobs are numbered
        // by JobLifecycle on create.
        static::updating(function (Job $job): void {
            if ($job->job_number && $job->isDirty('entity_id') && $job->entity_id) {
                $entity = Entity::find($job->entity_id);
                if ($entity) {
                    $job->job_number = static::generateNumberFor($entity);
                }
            }
        });

        // Tech pay is FIXED at the assigned technician's base cost, so it has to
        // follow the technician on EVERY write path — including a plain
        // update() from the job form's Scheduling section, which never reaches
        // JobLifecycle::applyTechFixedCost. Extra pay belongs on
        // additional_labor_cents, which is added on top.
        //
        // Two deliberate escapes: a save that writes the pay itself wins (that's
        // markTechPaid folding the day's total in, an import, or a factory), and
        // a job whose payout is already recorded is never touched — rewriting
        // that column would restate what we actually paid.
        static::saving(function (Job $job): void {
            if (! $job->isDirty('technician_id')
                || $job->isDirty('tech_pay_amount_cents')
                || $job->tech_paid_at !== null) {
                return;
            }

            $job->applyAssignedTechBaseCost();
        });
    }

    /**
     * Set tech pay to the assigned technician's fixed subcontractor cost — the
     * one rule for what a tech earns on a job before any additional labor.
     * Blanks the pay when there is no tech, and when the tech has no fixed cost
     * on file, so a gap stays visible instead of inheriting the previous tech's
     * number.
     */
    public function applyAssignedTechBaseCost(): void
    {
        $this->tech_pay_amount_cents = $this->technician_id === null
            ? null
            : Employee::whereKey($this->technician_id)->value('fixed_cost_cents');
    }

    /**
     * Next "CODE-YEAR-####" job number for an entity. Scans by PREFIX across ALL
     * entities (jobs.job_number is globally unique and the prefix already encodes
     * the entity code), taking the highest existing suffix + 1 — not a count — so
     * a gap from a hard-deleted job can't regenerate a live number. Includes
     * soft-deleted rows (they keep their unique number). lockForUpdate() lets a
     * caller inside a transaction serialize concurrent numbering and read the
     * latest committed max on a retry; it is a no-op on SQLite (tests).
     */
    public static function generateNumberFor(Entity $entity): string
    {
        $year = (int) now()->setTimezone(Timezone::display())->format('Y');
        $prefix = "{$entity->code}-{$year}-";

        $numbers = static::withTrashed()
            ->where('job_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('job_number');

        $max = 0;
        foreach ($numbers as $number) {
            $max = max($max, (int) substr((string) $number, strlen($prefix)));
        }

        return $prefix.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    // ---- Relations ----

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Individual payment lines (deposit + balance, possibly mixed methods). */
    public function payments(): HasMany
    {
        return $this->hasMany(JobPayment::class)->orderBy('collected_at')->orderBy('id');
    }

    /**
     * Logged CRM-style notes, oldest first. Named `noteEntries` because `notes`
     * is already the job's standing free-text column.
     */
    public function noteEntries(): HasMany
    {
        return $this->hasMany(JobNote::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * The same log including retracted notes — the feed renders those as
     * tombstones at their original time, so the history never has a silent gap.
     */
    public function noteEntriesWithDeleted(): HasMany
    {
        return $this->noteEntries()->withTrashed();
    }

    public function bookingSalesperson(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'booking_salesperson_id');
    }

    /**
     * Who booked this job, however we know it. A lead pushed in by the sales
     * dashboard names a rep who has no `employees` row here, so the name/phone
     * arrive as a SNAPSHOT; a manually intaken job names a real employee and
     * carries no snapshot. Read this everywhere the booking rep is displayed or
     * merged into a text, never the relation directly.
     */
    public function bookingSalespersonName(): ?string
    {
        return trim((string) $this->booking_salesperson_name) ?: $this->bookingSalesperson?->name;
    }

    /** The booking rep's phone, snapshot first (see bookingSalespersonName()). */
    public function bookingSalespersonPhone(): ?string
    {
        return trim((string) $this->booking_salesperson_phone) ?: $this->bookingSalesperson?->phone;
    }

    /** True when this job was pushed in by an upstream system (the sales dashboard). */
    public function isExternalIntake(): bool
    {
        return filled($this->external_source);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technician_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** Human label for the parts warehouse: the linked warehouse's name, else the "other" free text, else null. */
    public function warehouseLabel(): ?string
    {
        return $this->warehouse?->name ?: ($this->warehouse_other ?: null);
    }

    /**
     * The address to drive to, as one line: the service address with the city
     * appended when the street line doesn't already carry it (parsed leads
     * often split the two). Falls back to the customer's city. Null when we
     * have neither.
     */
    public function fullServiceAddress(): ?string
    {
        $address = trim((string) ($this->service_address ?? ''));
        $city = $this->city ?: $this->customer?->city;

        if ($city && ! Str::contains($address, $city, ignoreCase: true)) {
            $address = trim($address === '' ? $city : $address.', '.$city);
        }

        return $address !== '' ? $address : null;
    }

    public function scheduledByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'scheduled_by');
    }

    public function paymentRecordedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'payment_recorded_by');
    }

    public function paymentConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'payment_confirmed_by');
    }

    /** Bank-transaction match rows pointing at this job (customer payment, parts). */
    public function bankMatches(): HasMany
    {
        return $this->hasMany(BankTransactionMatch::class);
    }

    public function techPaidRecordedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'tech_paid_recorded_by');
    }

    public function partsOrderedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'parts_ordered_by');
    }

    public function partsPaidBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'parts_paid_by');
    }

    public function insurancePaidRecordedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'insurance_paid_recorded_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function scheduledMessages(): HasMany
    {
        return $this->hasMany(ScheduledMessage::class);
    }

    public function aiSchedulingSessions(): HasMany
    {
        return $this->hasMany(AiSchedulingSession::class);
    }

    /** The live "Schedule w/ AI" session for this job, if any — see AiSchedulingService::liveSessionFor. */
    public function liveAiSchedulingSession(): ?AiSchedulingSession
    {
        return $this->aiSchedulingSessions()
            ->whereIn('status', [AiSchedulingSession::STATUS_RUNNING, AiSchedulingSession::STATUS_PAUSED])
            ->latest('id')
            ->first();
    }

    /**
     * When the last still-unsent welcome text for this job is due to go out, or
     * null when none is pending (never sent one, or they've all left the outbox).
     *
     * The welcome is not a single text and is not immediate: the Podium-handoff
     * flow sends the handoff now and delays the Quo dispatch intro by
     * `glass.send.welcome_intro_delay_seconds`. A row still queued with no
     * `scheduled_for` was dispatched for immediate delivery, so its send time is
     * effectively now. Callers use this to keep a follow-up text BEHIND the
     * welcome — see JobLifecycle::followUpSendAtUtc.
     */
    public function pendingWelcomeSendAtUtc(): ?CarbonInterface
    {
        $pending = $this->scheduledMessages()
            ->where('message_type', MessageType::Welcome->value)
            ->unsent()
            ->get(['scheduled_for']);

        if ($pending->isEmpty()) {
            return null;
        }

        return $pending
            ->map(fn (ScheduledMessage $m) => $m->scheduled_for?->copy()->utc() ?? now()->utc())
            ->sortDesc()
            ->first();
    }

    public function reviewRequest(): HasOne
    {
        return $this->hasOne(ReviewRequest::class);
    }

    public function warrantyClaims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class);
    }

    // ---- Board exception-queue scopes ----

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', array_map(fn ($c) => $c->value, JobStatus::openCases()));
    }

    /**
     * The scheduling queue. Jobs ON HOLD are excluded — the work is still ours to
     * book, but we are waiting to hear back from the customer, so nobody can act
     * on it today. This one exclusion is what takes a held job off the board's
     * "Needs scheduling" tab + badge, Open Tasks, and the needs-scheduling
     * section of the three daily ops digests. Held jobs get their own board tab
     * and their own digest section (SchedulingHoldAlert) so they are still
     * chased, just not as work anybody can finish.
     */
    public function scopeNeedsScheduling(Builder $q): Builder
    {
        return $q->where('status', JobStatus::PendingSchedule->value)
            ->whereNull('scheduling_hold_at');
    }

    /** Jobs parked waiting on the customer — still unscheduled, just not actionable. */
    public function scopeOnSchedulingHold(Builder $q): Builder
    {
        return $q->where('status', JobStatus::PendingSchedule->value)
            ->whereNotNull('scheduling_hold_at');
    }

    public function isOnSchedulingHold(): bool
    {
        return $this->scheduling_hold_at !== null
            && $this->status === JobStatus::PendingSchedule;
    }

    /** Whole local days the job has been parked, 0 on the day it was held. */
    public function schedulingHoldDays(): ?int
    {
        if ($this->scheduling_hold_at === null) {
            return null;
        }

        return (int) Timezone::toDisplay($this->scheduling_hold_at)
            ->startOfDay()
            ->diffInDays(Timezone::now()->startOfDay());
    }

    /**
     * The customer's requested day as a real Y-m-d date, or null when the stored
     * value is legacy free text ("Tomorrow", "next Fri") the picker never wrote.
     */
    public function requestedDate(): ?string
    {
        $value = trim((string) $this->requested_day);
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The customer's time-of-day preference for their requested day. Legacy
     * jobs (taken before the column existed) fall back to reading their old
     * free-text `requested_time`, so an "As soon as possible" from last month
     * still classifies as ASAP rather than "we never asked".
     */
    public function timePreference(): TimePreference
    {
        return $this->requested_time_preference
            ?? TimePreference::normalize($this->requested_time)
            ?? TimePreference::None;
    }

    /**
     * The hour an "After" request starts from ('H:i'), falling back to reading
     * it out of the free text for a legacy row the backfill couldn't place.
     * Null for every other preference — nothing else carries one.
     */
    public function requestedAfterTime(): ?string
    {
        if ($this->timePreference() !== TimePreference::After) {
            return null;
        }

        return $this->requested_after_time
            ?: TimePreference::afterTimeFrom($this->requested_time);
    }

    /**
     * The requested TIME as a human reads it — "After 10am", "Morning", "2pm"
     * (the free text, for a specific request or a legacy row). '' when the
     * customer said nothing about time.
     */
    public function requestedTimeLabel(): string
    {
        $preference = $this->timePreference();

        if ($preference === TimePreference::After) {
            return $preference->shortLabel($this->requestedAfterTime());
        }

        return trim((string) $this->requested_time) ?: $preference->shortLabel();
    }

    /**
     * True when the customer asked for TODAY (Pacific) or a day already past —
     * an unscheduled job here is the urgent bucket of the morning alert.
     */
    public function isRequestedTodayOrOverdue(): bool
    {
        $date = $this->requestedDate();

        return $date !== null && $date <= Timezone::now()->format('Y-m-d');
    }

    public function scopeScheduled(Builder $q): Builder
    {
        return $q->where('status', JobStatus::Scheduled->value);
    }

    /**
     * Scheduled jobs whose part hasn't been ordered yet — the "Parts to Order" set.
     * Labor-only jobs are excluded: the customer brings the glass, so there is
     * nothing to order. This one exclusion cascades to partsReadyToOrder(), the
     * Parts page + nav badge, Open Tasks, the morning/same-day Telegram parts
     * alerts and the PartNotOrdered Issue.
     */
    public function scopePartsToOrder(Builder $q): Builder
    {
        return $q->scheduled()->whereNotNull('scheduled_at')
            ->where('labor_only', false)
            ->whereNull('parts_ordered_at');
    }

    /**
     * The partsToOrder() subset whose scheduled local date falls on or before
     * the next business day from now — i.e. it needs ordering right away.
     * Computed as a UTC cutoff so it stays SQL-only (works for nav badges).
     */
    public function scopePartsReadyToOrder(Builder $q): Builder
    {
        $cutoffLocal = BusinessDays::nextBusinessDay(Timezone::now());
        [, $cutoffUtc] = Timezone::localDayUtcRange($cutoffLocal);

        return $q->partsToOrder()->where('scheduled_at', '<=', $cutoffUtc);
    }

    /**
     * "Other"-supplier parts we've ordered but not yet prepaid — the money must
     * go out before the dealer releases the glass, so we don't end up with the
     * part on hold and unpaid. Drives the Parts to Order "Prepay pending" tab and
     * the prepay section of the daily ops digests (today + tomorrow). Open jobs only.
     */
    public function scopePrepayPending(Builder $q): Builder
    {
        return $q->open()
            ->where('labor_only', false)
            ->whereNull('warehouse_id')
            ->whereNotNull('warehouse_other')
            ->where('warehouse_other', '!=', '')
            ->whereNotNull('parts_ordered_at')
            ->whereNull('parts_paid_at');
    }

    /**
     * Jobs whose part the tech picked up before a cancellation and hasn't
     * returned yet — the Parts → "Parts returned" tab (parts awaiting return).
     * Status-agnostic: a canceled appointment goes back to PendingSchedule, but
     * the physical part is still out until it's brought back.
     */
    public function scopePartsReturnPending(Builder $q): Builder
    {
        return $q->whereNotNull('part_return_needed_at')->whereNull('part_returned_at');
    }

    /** Jobs scheduled on a given local (display-tz) calendar date. */
    public function scopeScheduledOnLocalDate(Builder $q, CarbonInterface|string $localDate): Builder
    {
        [$start, $end] = Timezone::localDayUtcRange($localDate);

        return $q->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$start, $end]);
    }

    public function scopeScheduledToday(Builder $q): Builder
    {
        return $q->scheduledOnLocalDate(Timezone::now());
    }

    public function scopeScheduledTomorrow(Builder $q): Builder
    {
        return $q->scheduledOnLocalDate(Timezone::now()->addDay());
    }

    public function scopeNoTechAssigned(Builder $q): Builder
    {
        return $q->open()->whereNull('technician_id');
    }

    /**
     * Scheduled jobs that have no technician — the "Needs tech assigned" queue.
     * Normally scheduling requires a tech, so this is the "Tech cancelled" state:
     * the appointment stands (still on the calendar) but its tech backed out.
     */
    public function scopeNeedsTechAssigned(Builder $q): Builder
    {
        return $q->scheduled()->whereNull('technician_id');
    }

    /** Open jobs with no parts source picked. Labor-only jobs never need one. */
    public function scopeNoWarehouse(Builder $q): Builder
    {
        return $q->open()->where('labor_only', false)->whereNull('warehouse_id')
            ->where(fn ($w) => $w->whereNull('warehouse_other')->orWhere('warehouse_other', ''));
    }

    /**
     * Work is done but the customer still owes us: either nothing recorded yet, or
     * only a partial (deposit) that leaves a balance against the quote. A fully
     * collected job drops off. (SQLite/MySQL-portable arithmetic on the summary
     * columns — no quote means no balance to chase, so those are excluded.)
     */
    public function scopeAwaitingPayment(Builder $q): Builder
    {
        return $q->whereNotNull('work_completed_at')
            ->where(function (Builder $inner) {
                $inner->whereNull('payment_received_at')
                    ->orWhereRaw('coalesce(quote_amount_cents, 0) > coalesce(amount_collected_cents, 0) and coalesce(quote_amount_cents, 0) > 0');
            });
    }

    public function scopeAwaitingTechPayout(Builder $q): Builder
    {
        return $q->whereNotNull('payment_received_at')->whereNull('tech_paid_at');
    }

    /**
     * Jobs that count toward a technician's open balance: work done, tech not yet
     * settled, and not canceled/lost. We pay techs for jobs they complete —
     * customer payment does NOT gate this (a completed job shows tech pay owed
     * immediately). Cash a tech collected is still netted when the customer
     * payment happens to be recorded; if it isn't yet, cash collected reads $0 and
     * the tech is paid full labor. Apply ->where('technician_id', ...) for one tech.
     */
    public function scopeSettleable(Builder $q): Builder
    {
        return $q->whereNotNull('work_completed_at')
            ->whereNull('tech_paid_at')
            ->whereNotIn('status', [JobStatus::Canceled->value, JobStatus::Lost->value]);
    }

    /** Jobs the customer has paid for (payment recorded). */
    public function scopePaid(Builder $q): Builder
    {
        return $q->whereNotNull('payment_received_at');
    }

    /**
     * Money the Xpro team collected on our behalf (Klarna / SwiftSwipe / Other -
     * Xpro Team) that we still have to collect back from them: has at least one
     * payment LINE via an Xpro method, not yet marked collected. Line-based, not
     * the summary payment_method — a job part-paid by Xpro and part by us (a
     * Zelle balance, say) must still show until the Xpro money is collected back.
     */
    public function scopeOwedFromXpro(Builder $q): Builder
    {
        return $q->whereNull('xpro_collected_at')
            ->whereHas('payments', fn (Builder $p) => $p->whereIn('method', PaymentMethod::xproValues()));
    }

    /**
     * Accounts-receivable "collected but unverified" set: payment recorded by a
     * non-cash method (Zelle/Venmo/Stripe), not manually confirmed, and either not
     * yet matched to a Chase transaction OR matched to one whose amount(s) don't
     * reconcile to the collected total (a "transaction amount mismatch" — kept
     * visible for review). Cash is excluded — the tech collects it in person.
     */
    public function scopeCollectedUnconfirmed(Builder $q): Builder
    {
        $tolerance = (int) config('glass.bank_matching.amount_tolerance_cents', 500);

        // Correlated subquery = deposit-positive sum of this job's confirmed
        // customer-payment transactions. Stays in AR unless that reconciles.
        $matchedSum = '(select coalesce(sum(-1 * bt.amount_cents), 0)'
            .' from bank_transaction_matches btm'
            .' join bank_transactions bt on bt.id = btm.bank_transaction_id'
            .' where btm.job_id = jobs.id and btm.kind = ? and bt.match_status = ?)';

        return $q->whereNotNull('payment_received_at')
            ->where('payment_method', '!=', PaymentMethod::Cash->value)
            // Xpro-collected methods live on their own "Owed from Xpro" page —
            // the money comes from the Xpro team, not a matchable Chase deposit.
            ->whereNotIn('payment_method', PaymentMethod::xproValues())
            ->whereNull('payment_confirmed_at')
            ->where(function (Builder $inner) use ($matchedSum, $tolerance) {
                $inner
                    // No confirmed customer-payment transaction at all → awaiting match.
                    ->whereDoesntHave('bankMatches', function (Builder $m) {
                        $m->where('kind', BankMatchKind::JobPayment->value)
                            ->whereHas('bankTransaction', fn (Builder $b) => $b->where('match_status', BankTransaction::STATUS_MATCHED));
                    })
                    // …or matched, but the amounts don't reconcile → mismatch.
                    ->orWhereRaw(
                        "abs($matchedSum - coalesce(jobs.amount_collected_cents, 0)) > ?",
                        [BankMatchKind::JobPayment->value, BankTransaction::STATUS_MATCHED, $tolerance]
                    );
            });
    }

    /** Every job flagged as an insurance job (regardless of insurance payment status). */
    public function scopeInsurance(Builder $q): Builder
    {
        return $q->where('is_insurance', true);
    }

    /** Insurance jobs still awaiting the carrier's payment. */
    public function scopeInsuranceUnpaid(Builder $q): Builder
    {
        return $q->insurance()->whereNull('insurance_paid_at');
    }

    /**
     * Unpaid insurance jobs that carry a quantified expected payout — the ones
     * that add up to the outstanding insurance receivable. An unpaid claim with
     * no expected amount is still on the Insurance page (it needs the amount
     * filling in), it just can't contribute a number to the total.
     */
    public function scopeInsuranceOutstanding(Builder $q): Builder
    {
        return $q->insuranceUnpaid()->where('insurance_expected_cents', '>', 0);
    }

    public function scopeFailedSms(Builder $q): Builder
    {
        return $q->whereHas('scheduledMessages', fn (Builder $m) => $m->where('status', MessageStatus::Failed->value));
    }

    public function scopeStalePending(Builder $q): Builder
    {
        $threshold = Timezone::now()->subHours((int) config('glass.stale_pending_hours', 48));

        return $q->where('status', JobStatus::PendingSchedule->value)
            ->where('created_at', '<', $threshold->utc());
    }

    public function scopeCanceledOrLost(Builder $q): Builder
    {
        return $q->whereIn('status', [JobStatus::Canceled->value, JobStatus::Lost->value]);
    }
}
