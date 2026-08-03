<?php

namespace App\Enums;

use App\Models\Holiday;
use Carbon\CarbonInterface;

enum TemplateKey: string
{
    case WelcomeWithTime = 'welcome_with_time';
    case WelcomeGeneric = 'welcome_generic';
    case WelcomeDispatchIntro = 'welcome_dispatch_intro';
    case WelcomeDispatchIntroAfterHours = 'welcome_dispatch_intro_afterhours';
    case WelcomeDispatchIntroSunday = 'welcome_dispatch_intro_sunday';
    case WelcomeDispatchIntroPreHolidayAfterHours = 'welcome_dispatch_intro_pre_holidayafterhours';
    case WelcomeDispatchIntroHoliday = 'welcome_dispatch_intro_holiday';
    case WelcomePodiumHandoff = 'welcome_podium_handoff';
    // The post-welcome "scheduling ask" family — one text, sent by hand right
    // after the welcome, that either ASKS for a time preference or ACKNOWLEDGES
    // the one we were given, and (on the *Future variants) offers to pull a
    // far-out appointment earlier. Which one applies is decided by
    // App\Support\SchedulingAsk from the job's requested day + time preference.
    case SchedulingAskNoDate = 'scheduling_ask_no_date';
    case SchedulingAskNextDay = 'scheduling_ask_next_day';
    case SchedulingAskFuture = 'scheduling_ask_future';
    case SchedulingConfirmNextDay = 'scheduling_confirm_next_day';
    case SchedulingConfirmFuture = 'scheduling_confirm_future';
    case TimeConfirmation = 'time_confirmation';
    // Same as TimeConfirmation, plus a note that ADAS calibration is a separate
    // visit scheduled right after the windshield install (calibration jobs only).
    case TimeConfirmationCalibration = 'time_confirmation_calibration';
    case AppointmentRescheduled = 'appointment_rescheduled';
    // "Cancel appointment" — customer needs a new time but doesn't have one yet.
    // Goes to the customer; the assigned tech gets TechJobCanceled, rendered in
    // the tech's own preferred language.
    case AppointmentCanceledPendingReschedule = 'appointment_canceled_pending_reschedule';
    case TechJobCanceled = 'tech_job_canceled';
    // Reminder to the assigned tech to return a part they'd already picked up,
    // sent when an appointment is canceled and dispatch confirms the part was
    // in the tech's hands (see JobLifecycle::cancelAppointment).
    case TechReturnPart = 'tech_return_part';
    case ReminderNightBefore = 'reminder_night_before';
    case ReminderMorningOf = 'reminder_morning_of';
    case TechReminderNightBefore = 'tech_reminder_night_before';
    case TechReminderMorningOf = 'tech_reminder_morning_of';
    // Opt-in nightly pay recap — one text per tech per day they completed work,
    // built in TechEodSummaryScheduler (no message_templates row, same as the
    // tech reminder digests). Gated per-tech by employees.eod_summary_texts_enabled.
    case TechEodSummary = 'tech_eod_summary';
    case TechJobAvailability = 'tech_job_availability';
    // The tech's copy of a booked job, texted the moment it has a date/time AND
    // an assigned tech (JobLifecycle::maybeSendTechJobConfirmation). Everything
    // they need to run it in one text: customer name + phone, full address,
    // arrival time, vehicle, part number, warehouse. Re-sent on a reschedule or
    // a tech change, since the details it carries have moved.
    case TechJobConfirmation = 'tech_job_confirmation';
    // The "Schedule w/ AI" negotiation family (see docs/ai-scheduling-spec.md).
    // Distinct from TechJobAvailability: that one asks an open "what time
    // works?" question and is idempotent once-per-job-per-tech forever, which
    // breaks a multi-turn negotiation. These propose a SPECIFIC slot and use
    // their own per-turn idempotency keys instead.
    case TechAiSlotOffer = 'tech_ai_slot_offer';
    // Pushback when a tech counters with a time the customer's constraint
    // doesn't admit — re-states the constraint and asks again, same tech.
    case TechAiSlotReiterate = 'tech_ai_slot_reiterate';
    // A tech who agreed to a whole part of day ("morning") gets told the
    // concrete 2-hour slot we intend to book, with a short window to come back
    // with a different time that still fits. Silence books what we proposed.
    case TechAiSlotConfirm = 'tech_ai_slot_confirm';
    // Same job as TechAiSlotConfirm, but for a tech who agreed by REACTING to
    // the offer (a thumbs up / a "Liked ..." relay) instead of writing back.
    // A tap is an ambiguous yes, so this copy says what we understood before
    // it names the slot. See ReplyInterpreter::reactionSentiment().
    case TechAiReactionConfirm = 'tech_ai_reaction_confirm';
    // Straight after the booking: which of our warehouses should the part be
    // ordered from? Their answer sets jobs.warehouse_id (see §4.5); no answer
    // just leaves it for the dispatcher who orders the part.
    case TechAiWarehouseAsk = 'tech_ai_warehouse_ask';
    case TechAiWarehouseSet = 'tech_ai_warehouse_set';
    // Sent to a tech who accepted after the job already went to someone else
    // (a race, or a tech who was superseded by a customer retarget).
    case TechAiFilled = 'tech_ai_filled';
    // Sent to the CUSTOMER only when a live session retargets onto a new
    // day/time because they texted back asking for something different.
    case CustomerAiTimeCheck = 'customer_ai_time_check';
    // The "Job Complete" family — one copy of the completion text per payment
    // method, picked when "Mark work complete" confirms how the customer wants
    // to pay (see JobLifecycle::confirmPaymentPreference). Zelle keeps the
    // original `job_complete` key and body; the rest started as clones of it.
    // Credit Card carries {payment_link}, so it is sent by the Stripe path in
    // JobLifecycle::createAndTextStripeInvoice rather than confirmPaymentPreference.
    case JobComplete = 'job_complete';
    case JobCompleteCreditCard = 'job_complete_credit_card';
    case JobCompleteVenmo = 'job_complete_venmo';
    case JobCompleteCash = 'job_complete_cash';
    case JobCompleteCheck = 'job_complete_check';
    // Completion text for a job the customer ALREADY paid before the work was
    // marked complete — a plain "your job is done, thank you" with NO payment
    // paragraph (nothing is owed). Sent by markWorkComplete when the job is
    // already paid, in place of the payment-flavored JobComplete family.
    case JobCompletePaid = 'job_complete_paid';
    // On-demand Stripe card payment link, sent from the standalone "Text card
    // payment link" job action (see JobLifecycle::createAndTextStripeInvoice).
    case PaymentLink = 'payment_link';
    // On-request receipt text, sent from the paid job's "Send receipt" action.
    case Receipt = 'receipt';
    // Multi-day install: sent when the SECOND visit gets a date, in place of the
    // time confirmation (which already went out for the first day).
    case MultiDayNextVisit = 'multi_day_next_visit';
    // One generic heads-up text when a warranty return visit is scheduled
    // (see App\Services\Warranty\WarrantyService).
    case WarrantyVisitScheduled = 'warranty_visit_scheduled';
    // Warranty return-visit reminders — the same night-before / morning-of cadence
    // as a normal appointment, but their own copy (keyed off the claim's visit
    // time via {warranty_date_time}). Customer-facing and tech-facing variants.
    case WarrantyReminderNightBefore = 'warranty_reminder_night_before';
    case WarrantyReminderMorningOf = 'warranty_reminder_morning_of';
    case TechWarrantyReminderNightBefore = 'tech_warranty_reminder_night_before';
    case TechWarrantyReminderMorningOf = 'tech_warranty_reminder_morning_of';
    // Sent when a warranty return visit is marked complete — a plain "all set"
    // wrap-up. NO review drip follows (unlike a normal job completion).
    case WarrantyCompleted = 'warranty_completed';
    // Call-first review flow (see App\Services\Reviews\ReviewDripService).
    // Text 1 when the first call actually connected — review_request_1 is
    // still text 1 for every other path (no answer, 7pm auto-fallthrough).
    case ReviewConnected = 'review_connected';
    case ReviewRequest1 = 'review_request_1';
    case ReviewRequest2 = 'review_request_2';
    case ReviewAfterCall = 'review_after_call';
    case ReviewVoicemail = 'review_voicemail';
    case ReviewFinal = 'review_final';
    case ReviewThankYou = 'review_thank_you';
    // After windshield install on a calibration job: partner (the calibration partner) will
    // reach out to schedule the separate calibration visit.
    case CalibrationPartnerOutreach = 'calibration_partner_outreach';
    // After ops thumbs-up/down confirms calibration — "your calibration is done".
    case CalibrationCompletedConfirm = 'calibration_completed_confirm';

    public function label(): string
    {
        return match ($this) {
            self::WelcomeWithTime => 'Welcome (with appointment time)',
            self::WelcomeGeneric => 'Welcome (generic, no time yet)',
            self::WelcomeDispatchIntro => 'Welcome — dispatch intro (Quo)',
            self::WelcomeDispatchIntroAfterHours => 'Welcome — dispatch intro, after-hours (Quo)',
            self::WelcomeDispatchIntroSunday => 'Welcome — dispatch intro, weekend/Sunday (Quo)',
            self::WelcomeDispatchIntroPreHolidayAfterHours => 'Welcome — dispatch intro, pre-holiday after-hours (Quo)',
            self::WelcomeDispatchIntroHoliday => 'Welcome — dispatch intro, holiday (Quo)',
            self::WelcomePodiumHandoff => 'Welcome — sales→dispatch handoff (Podium)',
            self::SchedulingAskNoDate => 'Scheduling ask — no date requested',
            self::SchedulingAskNextDay => 'Scheduling ask — next business day',
            self::SchedulingAskFuture => 'Scheduling ask — further out (offer earlier)',
            self::SchedulingConfirmNextDay => 'Scheduling confirm — next business day',
            self::SchedulingConfirmFuture => 'Scheduling confirm — further out (offer earlier)',
            self::TimeConfirmation => 'Time confirmation',
            self::TimeConfirmationCalibration => 'Time confirmation (with calibration visit note)',
            self::AppointmentRescheduled => 'Appointment rescheduled',
            self::AppointmentCanceledPendingReschedule => 'Appointment canceled — awaiting new time',
            self::TechJobCanceled => 'Tech — job canceled / off schedule',
            self::TechReturnPart => 'Tech — please return the part',
            self::ReminderNightBefore => 'Reminder — night before',
            self::ReminderMorningOf => 'Reminder — morning of',
            self::TechReminderNightBefore => 'Tech digest — night before',
            self::TechReminderMorningOf => 'Tech digest — morning of',
            self::TechEodSummary => 'Tech — end-of-day pay summary',
            self::TechJobAvailability => 'Tech availability request',
            self::TechJobConfirmation => 'Tech - job confirmation (full details)',
            self::TechAiSlotOffer => 'AI scheduling - slot offer to tech',
            self::TechAiSlotReiterate => 'AI scheduling - reiterate the customer\'s time to tech',
            self::TechAiSlotConfirm => 'AI scheduling - confirm the exact slot with tech',
            self::TechAiReactionConfirm => 'AI scheduling - confirm a thumbs-up reply with tech',
            self::TechAiWarehouseAsk => 'AI scheduling - ask tech which warehouse',
            self::TechAiWarehouseSet => 'AI scheduling - confirm the warehouse to tech',
            self::TechAiFilled => 'AI scheduling - job already filled',
            self::CustomerAiTimeCheck => 'AI scheduling - confirm a new time with customer',
            self::JobComplete => 'Job Complete - Zelle',
            self::JobCompleteCreditCard => 'Job Complete - Credit Card',
            self::JobCompleteVenmo => 'Job Complete - Venmo',
            self::JobCompleteCash => 'Job Complete - Cash',
            self::JobCompleteCheck => 'Job Complete - Check',
            self::JobCompletePaid => 'Job Complete - Already Paid (no payment info)',
            self::PaymentLink => 'Card payment link (Stripe)',
            self::Receipt => 'Receipt (after payment)',
            self::MultiDayNextVisit => 'Multi-day install - next visit scheduled',
            self::WarrantyVisitScheduled => 'Warranty visit scheduled',
            self::WarrantyReminderNightBefore => 'Warranty reminder - night before',
            self::WarrantyReminderMorningOf => 'Warranty reminder - morning of',
            self::TechWarrantyReminderNightBefore => 'Tech warranty reminder - night before',
            self::TechWarrantyReminderMorningOf => 'Tech warranty reminder - morning of',
            self::WarrantyCompleted => 'Warranty visit completed',
            self::ReviewConnected => 'Review — after first call (connected)',
            self::ReviewRequest1 => 'Review — request 1 (no-answer / auto-fallthrough)',
            self::ReviewRequest2 => 'Review — request 2 (next-day follow-up)',
            self::ReviewAfterCall => 'Review — after call (connected)',
            self::ReviewVoicemail => 'Review — template 3 (after voicemail)',
            self::ReviewFinal => 'Review — final (1 week later)',
            self::ReviewThankYou => 'Review — thank you (review left)',
            self::CalibrationPartnerOutreach => 'Calibration — partner will reach out',
            self::CalibrationCompletedConfirm => 'Calibration — completed confirmation',
        };
    }

    /**
     * The outbound provider a template naturally belongs to, or null to use the
     * globally-configured default. The handoff template is number-specific: the
     * Podium handoff must go FROM the sales thread, the dispatch intros from our
     * Quo line. Everything else rides the default provider.
     */
    public function preferredProvider(): ?string
    {
        return match ($this) {
            self::WelcomePodiumHandoff => 'podium',
            self::WelcomeDispatchIntro, self::WelcomeDispatchIntroAfterHours,
            self::WelcomeDispatchIntroSunday, self::WelcomeDispatchIntroPreHolidayAfterHours,
            self::WelcomeDispatchIntroHoliday,
            // The scheduling ask continues the dispatch-intro thread, so it has
            // to come from the same (Quo) number the intro came from.
            self::SchedulingAskNoDate, self::SchedulingAskNextDay, self::SchedulingAskFuture,
            self::SchedulingConfirmNextDay, self::SchedulingConfirmFuture,
            // Tech availability outreach goes out from our Quo line.
            self::TechJobAvailability, self::TechJobConfirmation, self::TechJobCanceled,
            self::TechReturnPart,
            // The whole AI scheduling negotiation — both the tech side and the
            // customer retarget check — continues the same Quo thread the
            // welcome/dispatch-intro texts started on.
            self::TechAiSlotOffer, self::TechAiSlotReiterate, self::TechAiSlotConfirm, self::TechAiReactionConfirm,
            self::TechAiWarehouseAsk, self::TechAiWarehouseSet, self::TechAiFilled,
            self::CustomerAiTimeCheck => 'openphone',
            default => null,
        };
    }

    /**
     * Who this template talks to. Drives the Message Templates audience filter
     * and, more importantly, WHOSE language preference picks the row that gets
     * rendered — the customer's or the technician's (see OutboxService).
     */
    public function audience(): Audience
    {
        return match ($this) {
            self::TechJobCanceled, self::TechJobAvailability, self::TechJobConfirmation,
            self::TechReturnPart,
            self::TechReminderNightBefore, self::TechReminderMorningOf, self::TechEodSummary,
            self::TechWarrantyReminderNightBefore, self::TechWarrantyReminderMorningOf,
            self::TechAiSlotOffer, self::TechAiSlotReiterate, self::TechAiSlotConfirm, self::TechAiReactionConfirm,
            self::TechAiWarehouseAsk, self::TechAiWarehouseSet, self::TechAiFilled => Audience::Tech,
            default => Audience::Customer,
        };
    }

    /**
     * Template-key values addressed to $audience, for filtering the templates
     * table in SQL (audience is derived, never stored on the row).
     *
     * @return array<int, string>
     */
    public static function valuesFor(Audience $audience): array
    {
        return array_values(array_map(
            fn (self $key) => $key->value,
            array_filter(self::cases(), fn (self $key) => $key->audience() === $audience),
        ));
    }

    /**
     * Which stage of the job this template belongs to — how the Message
     * Templates dashboard is organized. Display-only: nothing about sending
     * keys off this (see MessageType for that), so a template can be filed
     * where an operator would look for it rather than where the pipeline
     * treats it. Notably the scheduling asks are MessageType::Welcome but live
     * under Scheduling, and warranty/calibration texts are Confirmations but
     * get their own buckets.
     */
    public function category(): TemplateCategory
    {
        return match ($this) {
            self::WelcomeWithTime, self::WelcomeGeneric,
            self::WelcomeDispatchIntro, self::WelcomeDispatchIntroAfterHours,
            self::WelcomeDispatchIntroSunday, self::WelcomeDispatchIntroPreHolidayAfterHours,
            self::WelcomeDispatchIntroHoliday, self::WelcomePodiumHandoff => TemplateCategory::Welcome,

            self::SchedulingAskNoDate, self::SchedulingAskNextDay, self::SchedulingAskFuture,
            self::SchedulingConfirmNextDay, self::SchedulingConfirmFuture,
            self::TimeConfirmation, self::TimeConfirmationCalibration,
            self::AppointmentRescheduled, self::AppointmentCanceledPendingReschedule,
            self::MultiDayNextVisit, self::CustomerAiTimeCheck => TemplateCategory::Scheduling,

            self::ReminderNightBefore, self::ReminderMorningOf,
            self::TechReminderNightBefore, self::TechReminderMorningOf => TemplateCategory::Reminders,

            self::JobComplete, self::JobCompleteCreditCard, self::JobCompleteVenmo,
            self::JobCompleteCash, self::JobCompleteCheck,
            self::JobCompletePaid => TemplateCategory::Completion,

            self::PaymentLink, self::Receipt => TemplateCategory::Payment,

            self::ReviewConnected,
            self::ReviewRequest1, self::ReviewRequest2, self::ReviewAfterCall,
            self::ReviewVoicemail, self::ReviewFinal,
            self::ReviewThankYou => TemplateCategory::Reviews,

            // The whole warranty family stays together — including its own
            // reminders, which read as warranty copy, not appointment copy.
            self::WarrantyVisitScheduled, self::WarrantyCompleted,
            self::WarrantyReminderNightBefore, self::WarrantyReminderMorningOf,
            self::TechWarrantyReminderNightBefore,
            self::TechWarrantyReminderMorningOf => TemplateCategory::Warranty,

            self::CalibrationPartnerOutreach,
            self::CalibrationCompletedConfirm => TemplateCategory::Calibration,

            self::TechJobAvailability, self::TechJobConfirmation, self::TechJobCanceled,
            self::TechReturnPart, self::TechEodSummary,
            self::TechAiSlotOffer, self::TechAiSlotReiterate, self::TechAiSlotConfirm, self::TechAiReactionConfirm,
            self::TechAiWarehouseAsk, self::TechAiWarehouseSet, self::TechAiFilled => TemplateCategory::TechCrew,
        };
    }

    /**
     * Template-key values in $category, for filtering the templates table in
     * SQL (category is derived, never stored on the row).
     *
     * @return array<int, string>
     */
    public static function valuesForCategory(TemplateCategory $category): array
    {
        return array_values(array_map(
            fn (self $key) => $key->value,
            array_filter(self::cases(), fn (self $key) => $key->category() === $category),
        ));
    }

    /**
     * Pick the dispatch-intro variant for the moment the welcome is sent, in the
     * display timezone. Precedence:
     *   1. Holiday today (any time)              → holiday variant
     *   2. Sunday (any time) / Saturday ≥4pm     → weekend/Sunday variant
     *   3. Mon–Fri ≥7pm:
     *        - tomorrow is a holiday             → pre-holiday after-hours variant
     *        - otherwise                         → after-hours variant
     *   4. Everything else (business hours)      → plain intro
     */
    public static function welcomeDispatchIntroFor(CarbonInterface $localNow): self
    {
        // 1. Any time on a holiday.
        if (Holiday::isHoliday($localNow)) {
            return self::WelcomeDispatchIntroHoliday;
        }

        // 2. All day Sunday, or Saturday after 4pm.
        if ($localNow->isSunday()) {
            return self::WelcomeDispatchIntroSunday;
        }
        if ($localNow->isSaturday()) {
            return $localNow->hour >= 16 ? self::WelcomeDispatchIntroSunday : self::WelcomeDispatchIntro;
        }

        // 3. Mon–Fri after 7pm — pre-holiday variant when the NEXT day is a holiday.
        if ($localNow->hour >= 19) {
            return Holiday::isHoliday($localNow->copy()->addDay())
                ? self::WelcomeDispatchIntroPreHolidayAfterHours
                : self::WelcomeDispatchIntroAfterHours;
        }

        // 4. Business hours (Mon–Fri before 7pm, Saturday before 4pm).
        return self::WelcomeDispatchIntro;
    }

    public function messageType(): MessageType
    {
        return match ($this) {
            self::WelcomeWithTime, self::WelcomeGeneric,
            self::WelcomeDispatchIntro, self::WelcomeDispatchIntroAfterHours,
            self::WelcomeDispatchIntroSunday, self::WelcomeDispatchIntroPreHolidayAfterHours,
            self::WelcomeDispatchIntroHoliday, self::WelcomePodiumHandoff,
            // Part of the intake conversation, right after the welcome.
            self::SchedulingAskNoDate, self::SchedulingAskNextDay, self::SchedulingAskFuture,
            self::SchedulingConfirmNextDay, self::SchedulingConfirmFuture => MessageType::Welcome,
            self::TimeConfirmation, self::TimeConfirmationCalibration,
            self::AppointmentRescheduled,
            self::AppointmentCanceledPendingReschedule,
            self::MultiDayNextVisit,
            self::WarrantyVisitScheduled, self::WarrantyCompleted,
            self::CalibrationPartnerOutreach, self::CalibrationCompletedConfirm,
            self::CustomerAiTimeCheck => MessageType::Confirmation,
            self::ReminderNightBefore, self::ReminderMorningOf,
            self::TechReminderNightBefore, self::TechReminderMorningOf, self::TechEodSummary,
            self::WarrantyReminderNightBefore, self::WarrantyReminderMorningOf,
            self::TechWarrantyReminderNightBefore, self::TechWarrantyReminderMorningOf => MessageType::Reminder,
            self::TechJobAvailability, self::TechJobConfirmation, self::TechJobCanceled,
            self::TechReturnPart,
            self::TechAiSlotOffer, self::TechAiSlotReiterate, self::TechAiSlotConfirm, self::TechAiReactionConfirm,
            self::TechAiWarehouseAsk, self::TechAiWarehouseSet, self::TechAiFilled => MessageType::TechOutreach,
            self::JobComplete, self::JobCompleteCreditCard, self::JobCompleteVenmo,
            self::JobCompleteCash, self::JobCompleteCheck, self::JobCompletePaid => MessageType::Completion,
            self::PaymentLink, self::Receipt => MessageType::Payment,
            self::ReviewConnected,
            self::ReviewRequest1, self::ReviewRequest2, self::ReviewAfterCall,
            self::ReviewVoicemail, self::ReviewFinal, self::ReviewThankYou => MessageType::Review,
        };
    }

    /**
     * Merge variables that MUST resolve to a non-empty value before this
     * template may be queued. Kept minimal: only the dynamic facts whose
     * absence would make the message wrong (e.g. an appointment time). Names
     * and other niceties render gracefully as empty strings.
     */
    public function requiredVars(): array
    {
        return match ($this) {
            self::WelcomeWithTime => ['scheduled_date_time'],
            self::TimeConfirmation, self::TimeConfirmationCalibration,
            self::AppointmentRescheduled => ['scheduled_date_time'],
            self::ReminderNightBefore, self::ReminderMorningOf => ['scheduled_date_time'],
            // The whole point of this text is the second visit's date/time.
            self::MultiDayNextVisit => ['scheduled_date_time'],
            // Review-ask texts are pointless without the entity's review link set
            // (Admin → Entities), so refuse to queue until it exists.
            self::ReviewConnected,
            self::ReviewRequest1, self::ReviewRequest2, self::ReviewAfterCall,
            self::ReviewVoicemail, self::ReviewFinal => ['google_review_url'],
            // Pointless to send either card text without the actual link.
            self::PaymentLink, self::JobCompleteCreditCard => ['payment_link'],
            // A receipt only makes sense once the PDF link is built (a fresh
            // 30-day signed URL, passed as an extra var by
            // JobActions::sendReceipt / JobLifecycle::sendManualMessage — it
            // can't be derived from the job alone since it needs a signature
            // minted at send time). {amount_paid}/{payment_method}/
            // {payment_date} are still supported, just optional now.
            self::Receipt => ['receipt_link'],
            // The warranty text is wrong without the visit's date/time (passed
            // as an extra var by WarrantyService when the visit is scheduled).
            self::WarrantyVisitScheduled,
            self::WarrantyReminderNightBefore, self::WarrantyReminderMorningOf,
            self::TechWarrantyReminderNightBefore, self::TechWarrantyReminderMorningOf => ['warranty_date_time'],
            // The scheduling ask is wrong without the fact it is built around:
            // the day we are proposing, the day they asked for, and (on the
            // pull-earlier variants) the earlier days we are offering.
            self::SchedulingAskNoDate => ['earliest_day'],
            self::SchedulingAskNextDay => ['requested_day'],
            self::SchedulingAskFuture => ['requested_day', 'earlier_window'],
            self::SchedulingConfirmNextDay => ['requested_day', 'requested_time'],
            self::SchedulingConfirmFuture => ['requested_day', 'requested_time', 'earlier_window'],
            // The whole point of these two is the specific slot proposed / the
            // constraint being re-stated — pointless (and unreadable) without it.
            self::TechAiSlotOffer, self::TechAiSlotConfirm, self::TechAiReactionConfirm,
            self::CustomerAiTimeCheck => ['slot_phrase'],
            // Confirming the part source back to the tech is the entire point
            // of this one - without the name it says nothing.
            self::TechAiWarehouseSet => ['warehouse'],
            self::TechAiSlotReiterate => ['constraint_phrase'],
            // The tech's job card is built around the arrival time; the rest of
            // its lines fall back to a placeholder rather than blocking a send.
            self::TechJobConfirmation => ['scheduled_date_time'],
            self::WelcomeGeneric, self::WelcomeDispatchIntro, self::WelcomeDispatchIntroAfterHours,
            self::WelcomeDispatchIntroSunday, self::WelcomeDispatchIntroPreHolidayAfterHours,
            self::WelcomeDispatchIntroHoliday, self::WelcomePodiumHandoff,
            self::TechReminderNightBefore, self::TechReminderMorningOf, self::TechEodSummary,
            self::TechJobAvailability, self::TechJobCanceled, self::TechReturnPart,
            self::TechAiWarehouseAsk, self::TechAiFilled,
            self::AppointmentCanceledPendingReschedule,
            // The Job Complete bodies spell their payment details out in the copy
            // rather than pulling a {zelle_details}-style merge var, so there is
            // nothing that must resolve before they can queue.
            self::JobComplete, self::JobCompleteVenmo, self::JobCompleteCash,
            self::JobCompleteCheck, self::JobCompletePaid, self::ReviewThankYou,
            self::WarrantyCompleted,
            self::CalibrationPartnerOutreach, self::CalibrationCompletedConfirm => [],
        };
    }

    /**
     * Templates whose bodies MUST carry opt-out language, and — just as
     * importantly — the only ones allowed to. Customers can opt out of the
     * review drip and nothing else: the rest of the pipeline is transactional
     * (their appointment, their payment, their receipt), so inviting a STOP
     * there would cost them texts they actually need. Derived from the message
     * type rather than a hand-listed set, so a new review template can't be
     * added without the opt-out.
     *
     * Enforced on the body in MessageTemplateForm, both directions.
     */
    public function requiresOptOutLanguage(): bool
    {
        return $this->messageType() === MessageType::Review;
    }
}
