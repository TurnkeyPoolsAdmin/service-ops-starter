<?php

namespace App\Services\Messaging;

use App\Enums\Audience;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\TemplateKey;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Job;
use App\Models\ScheduledMessage;
use App\Models\Setting;
use App\Support\PhoneNumber;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The ONLY way an automated message enters the outbound pipeline. Controllers,
 * Filament actions, and the lifecycle service all route through here. A worker
 * (SendSmsJob) later picks up queued rows and sends them — never this service.
 *
 * Guarantees:
 *  - Duplicate rows for the same lifecycle event are impossible (idempotency_key
 *    unique constraint + firstOrCreate).
 *  - A customer who has opted out (do_not_text) never gets a queued row.
 *  - Every message renders in its RECIPIENT's language: the customer's for
 *    customer texts, the technician's for tech texts (untranslated templates
 *    fall back to English — see TemplateRenderer::resolveTemplate).
 */
class OutboxService
{
    public function __construct(private TemplateRenderer $renderer) {}

    /** Name of the employee a send is attributed to, for the {dispatch} merge var. */
    private static function dispatchName(?int $createdBy): ?string
    {
        return $createdBy ? Employee::find($createdBy)?->name : null;
    }

    /**
     * Queue a templated message for a job. Returns the row, or null when the
     * pre-send gate blocks it (opted out) or rendering is impossible.
     *
     * $idempotencyKey overrides the default (job+template+send-time) key. Pass it
     * for events that recur for the same template — e.g. a reschedule notice,
     * which should send once per reschedule (keyed on the reschedule count) even
     * though its send time is null.
     */
    public function queueForJob(
        Job $job,
        TemplateKey $key,
        ?CarbonInterface $scheduledForUtc = null,
        ?int $createdBy = null,
        ?string $provider = null,
        ?string $idempotencyKey = null,
        array $extraVars = [],
    ): ?ScheduledMessage {
        $job->loadMissing('customer', 'entity');
        $customer = $job->customer;

        // Pre-send gate: opted-out customers get no automated outbound SMS.
        if ($customer->do_not_text) {
            return null;
        }

        // Per-lead pause: a paused lead gets NO automated texts. Manual sends use
        // queueManual() (no pause check) so dispatchers can still text by hand.
        if ($customer->auto_text_paused) {
            return null;
        }

        $fromPhone = Setting::get('sending_phone_e164');
        if (blank($fromPhone)) {
            Log::warning('OutboxService: no sending_phone_e164 configured; cannot queue message.', [
                'job_id' => $job->id, 'template' => $key->value,
            ]);

            return null;
        }

        try {
            $body = $this->renderer->renderForJob(
                $key, $job, self::dispatchName($createdBy), $extraVars, $customer->preferredLanguage(),
            );
        } catch (TemplateRenderException $e) {
            Log::warning('OutboxService: render failed, message not queued.', [
                'job_id' => $job->id, 'template' => $key->value, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        $idempotencyKey ??= ScheduledMessage::makeIdempotencyKey($job->id, $key, $scheduledForUtc);

        // firstOrCreate on the unique idempotency_key makes duplicates impossible.
        return ScheduledMessage::firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'job_id' => $job->id,
                'customer_id' => $customer->id,
                'entity_id' => $job->entity_id,
                'template_key' => $key->value,
                'message_type' => $key->messageType()->value,
                'to_phone_e164' => $customer->phone_e164,
                'from_phone_e164' => $fromPhone,
                'provider' => $provider,
                'rendered_body' => $body,
                'scheduled_for' => $scheduledForUtc?->copy()->utc(),
                'status' => MessageStatus::Queued->value,
                'created_by' => $createdBy,
            ],
        );
    }

    /**
     * Queue a one-off, dispatcher-initiated send of a template for a job (the
     * "Messaging" dropdown on the job view). Unlike queueForJob, each manual
     * send is its OWN row (unique idempotency key) so the same template can be
     * sent more than once, and it's typed as `manual` so the worker's
     * lifecycle skip rules (e.g. reminder-after-completion) don't suppress an
     * explicit human choice. Opt-out and render gates still
     * apply. Returns the row, or null when blocked.
     *
     * $scheduledForUtc defers the send instead of firing it now — used to hold a
     * follow-up behind a welcome text that's still waiting in the outbox. The
     * every-minute processor picks it up as a fallback either way (scopeDue).
     */
    public function queueManual(
        Job $job,
        TemplateKey $key,
        ?int $createdBy = null,
        ?string $provider = null,
        array $extraVars = [],
        ?CarbonInterface $scheduledForUtc = null,
    ): ?ScheduledMessage {
        $job->loadMissing('customer', 'entity');
        $customer = $job->customer;

        if ($customer->do_not_text) {
            return null;
        }

        $fromPhone = Setting::get('sending_phone_e164');
        if (blank($fromPhone)) {
            Log::warning('OutboxService: no sending_phone_e164 configured; cannot queue manual message.', [
                'job_id' => $job->id, 'template' => $key->value,
            ]);

            return null;
        }

        try {
            $body = $this->renderer->renderForJob(
                $key, $job, self::dispatchName($createdBy), $extraVars, $customer->preferredLanguage(),
            );
        } catch (TemplateRenderException $e) {
            Log::warning('OutboxService: manual render failed, message not queued.', [
                'job_id' => $job->id, 'template' => $key->value, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        return ScheduledMessage::create([
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'entity_id' => $job->entity_id,
            'template_key' => $key->value,
            'message_type' => MessageType::Manual->value,
            'to_phone_e164' => $customer->phone_e164,
            'from_phone_e164' => $fromPhone,
            'provider' => $provider ?? $key->preferredProvider(),
            'rendered_body' => $body,
            'scheduled_for' => $scheduledForUtc?->copy()->utc(),
            'status' => MessageStatus::Queued->value,
            // Unique per send so a manual resend never collides with a prior row.
            'idempotency_key' => 'job:'.$job->id.':tpl:'.$key->value.':manual:'.Str::uuid(),
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Queue a pre-rendered reminder digest addressed to a technician (no
     * customer, spans multiple jobs). Idempotent on the caller-supplied key so a
     * tech only gets one digest per phase per day. Returns the row, or null when
     * the tech has no usable phone or no sending number is configured.
     */
    public function queueTechDigest(
        Employee $tech,
        TemplateKey $key,
        string $body,
        CarbonInterface $scheduledForUtc,
        string $idempotencyKey,
    ): ?ScheduledMessage {
        $toPhone = PhoneNumber::toE164($tech->phone);
        if (blank($toPhone)) {
            Log::warning('OutboxService: tech has no valid phone; digest not queued.', [
                'employee_id' => $tech->id, 'template' => $key->value,
            ]);

            return null;
        }

        $fromPhone = Setting::get('sending_phone_e164');
        if (blank($fromPhone)) {
            Log::warning('OutboxService: no sending_phone_e164 configured; tech digest not queued.', [
                'employee_id' => $tech->id, 'template' => $key->value,
            ]);

            return null;
        }

        return ScheduledMessage::firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'employee_id' => $tech->id,
                'entity_id' => $tech->entity_id,
                'template_key' => $key->value,
                'message_type' => $key->messageType()->value,
                'to_phone_e164' => $toPhone,
                'from_phone_e164' => $fromPhone,
                'rendered_body' => $body,
                'scheduled_for' => $scheduledForUtc->copy()->utc(),
                'status' => MessageStatus::Queued->value,
            ],
        );
    }

    /**
     * Queue a job-templated message addressed to a TECHNICIAN (not the customer)
     * — e.g. the "can you take this job?" availability request. Renders from the
     * same job merge-vars (city, vehicle, part, etc.), in the TECH's preferred
     * language, but delivers to the tech's phone. The customer opt-out gate does NOT apply (the recipient
     * isn't the customer). Idempotent per job + tech + template so a double-click
     * can't double-text the same tech about the same job. Returns the row, or
     * null when the tech has no usable phone, no sending number is set, or the
     * template can't render.
     */
    public function queueJobMessageForTech(
        Job $job,
        Employee $tech,
        TemplateKey $key,
        ?int $createdBy = null,
        ?CarbonInterface $scheduledForUtc = null,
        array $extraVars = [],
        ?string $idempotencyKey = null,
    ): ?ScheduledMessage {
        $job->loadMissing('entity');

        $toPhone = PhoneNumber::toE164($tech->phone);
        if (blank($toPhone)) {
            Log::warning('OutboxService: tech has no valid phone; outreach not queued.', [
                'employee_id' => $tech->id, 'job_id' => $job->id, 'template' => $key->value,
            ]);

            return null;
        }

        $fromPhone = Setting::get('sending_phone_e164');
        if (blank($fromPhone)) {
            Log::warning('OutboxService: no sending_phone_e164 configured; tech outreach not queued.', [
                'employee_id' => $tech->id, 'job_id' => $job->id, 'template' => $key->value,
            ]);

            return null;
        }

        try {
            $body = $this->renderer->renderForJob(
                $key, $job, self::dispatchName($createdBy), $extraVars, $tech->preferredLanguage(),
            );
        } catch (TemplateRenderException $e) {
            Log::warning('OutboxService: tech outreach render failed, message not queued.', [
                'employee_id' => $tech->id, 'job_id' => $job->id, 'template' => $key->value, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        $idempotencyKey ??= "job:{$job->id}:tech:{$tech->id}:tpl:{$key->value}";

        return ScheduledMessage::firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'job_id' => $job->id,
                'employee_id' => $tech->id,
                'entity_id' => $job->entity_id,
                'template_key' => $key->value,
                'message_type' => $key->messageType()->value,
                'to_phone_e164' => $toPhone,
                'from_phone_e164' => $fromPhone,
                'provider' => $key->preferredProvider(),
                'rendered_body' => $body,
                'scheduled_for' => $scheduledForUtc?->copy()->utc(),
                'status' => MessageStatus::Queued->value,
                'created_by' => $createdBy,
            ],
        );
    }

    /**
     * Re-render every queued-but-unsent message for a customer in their CURRENT
     * language, and return how many bodies changed.
     *
     * Bodies are rendered when a message is queued, not when it's sent, so a
     * reminder queued days ahead has its English text baked in long before the
     * dispatcher discovers the customer speaks Spanish. Flipping the language
     * has to reach back and rewrite what's still in the outbox, otherwise
     * "texts in Spanish" only takes effect on the next job.
     *
     * Rows that can no longer render are left exactly as they are: a warranty
     * text, for instance, was queued with a {warranty_date_time} passed in by
     * the caller, and that value isn't stored on the row — re-rendering would
     * fail its required-var check. A stale-but-correct body beats a broken one.
     */
    public function rerenderUnsentForCustomer(Customer $customer): int
    {
        $language = $customer->preferredLanguage();

        $rows = $customer->scheduledMessages()
            ->unsent()
            ->whereNotNull('job_id')
            ->with('job')
            ->get();

        $changed = 0;

        foreach ($rows as $message) {
            $key = $message->template_key;
            $job = $message->job;

            // Tech-addressed rows follow the crew's language, not the customer's.
            if ($key === null || $job === null || $key->audience() !== Audience::Customer) {
                continue;
            }

            try {
                $body = $this->renderer->renderForJob(
                    $key, $job, self::dispatchName($message->created_by), language: $language,
                );
            } catch (TemplateRenderException $e) {
                Log::info('OutboxService: could not re-render queued message for language change; left as-is.', [
                    'scheduled_message_id' => $message->id, 'template' => $key->value, 'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($body !== $message->rendered_body) {
                $message->update(['rendered_body' => $body]);
                $changed++;
            }
        }

        return $changed;
    }

    /** Cancel unsent rows for a job, optionally restricted to message types. */
    public function cancelUnsentForJob(Job $job, ?array $messageTypes = null, string $reason = 'job change'): int
    {
        $query = $job->scheduledMessages()->unsent();

        if ($messageTypes !== null) {
            $query->whereIn('message_type', $messageTypes);
        }

        return $this->cancelQuery($query, $reason);
    }

    /**
     * Cancel unsent rows for a job restricted to a set of template keys — used
     * when a warranty visit is rescheduled to wipe the old visit's reminders
     * (customer + tech) before the new ones are queued, without disturbing the
     * job's own lifecycle reminders (which share the `reminder` message type).
     *
     * @param  array<int, TemplateKey>  $keys
     */
    public function cancelUnsentForJobTemplates(Job $job, array $keys, string $reason = 'rescheduled'): int
    {
        if ($keys === []) {
            return 0;
        }

        $query = $job->scheduledMessages()->unsent()
            ->whereIn('template_key', array_map(fn (TemplateKey $k) => $k->value, $keys));

        return $this->cancelQuery($query, $reason);
    }

    /** Cancel ALL unsent rows for a customer across every job (opt-out). */
    public function cancelUnsentForCustomer(Customer $customer, string $reason = 'customer opted out'): int
    {
        return $this->cancelQuery($customer->scheduledMessages()->unsent(), $reason);
    }

    private function cancelQuery($query, string $reason): int
    {
        return $query->get()->reduce(function (int $carry, ScheduledMessage $m) use ($reason) {
            $m->update([
                'status' => MessageStatus::Canceled->value,
                'canceled_at' => now(),
                'skip_reason' => $reason,
            ]);

            return $carry + 1;
        }, 0);
    }
}
