<?php

namespace App\Services\Messaging;

use App\Enums\DetectedIntent;
use App\Enums\OptOutSource;
use App\Jobs\ProcessAiSchedulingReply;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\InboundMessage;
use App\Services\AiScheduling\AiSchedulingService;
use App\Services\AiScheduling\TechOutreach;
use App\Services\JobLifecycle;
use App\Support\PhoneNumber;
use Carbon\Carbon;

class InboundMessageService
{
    public function __construct(
        private OptOutDetector $detector,
        private JobLifecycle $lifecycle,
    ) {}

    /**
     * Record an inbound message, match it to a customer (and, since AI
     * scheduling, to a technician), act on opt-out, and hand anything that
     * belongs to a live scheduling negotiation to a queued worker.
     *
     * @param  array  $data  keys: from_phone, to_phone, body, provider_message_id, received_at, raw_payload
     */
    public function handle(array $data): InboundMessage
    {
        $fromE164 = PhoneNumber::toE164($data['from_phone'] ?? null) ?? ($data['from_phone'] ?? '');
        $customer = $fromE164 ? Customer::where('phone_e164', $fromE164)->first() : null;
        $employee = $fromE164 ? self::matchEmployee($fromE164) : null;

        $isOptOut = $this->detector->isOptOut($data['body'] ?? null);
        $intent = $isOptOut ? DetectedIntent::OptOut : DetectedIntent::Other;

        $inbound = InboundMessage::create([
            'customer_id' => $customer?->id,
            'employee_id' => $employee?->id,
            'from_phone_e164' => $fromE164,
            'to_phone_e164' => $data['to_phone'] ?? '',
            'body' => $data['body'] ?? null,
            'received_at' => isset($data['received_at']) ? Carbon::parse($data['received_at']) : now(),
            'provider_message_id' => $data['provider_message_id'] ?? null,
            'raw_payload' => $data['raw_payload'] ?? null,
            'detected_intent' => $intent->value,
            'processed' => false,
        ]);

        if ($customer) {
            $customer->forceFill(['last_inbound_at' => $inbound->received_at])->save();

            if ($isOptOut && ! $customer->do_not_text) {
                $this->lifecycle->optOutCustomer($customer, OptOutSource::InboundSms, $data['body'] ?? null);
            }
        }

        // A technician's STOP is handled here and now, synchronously, and is
        // NEVER routed into the negotiation — "STOP" would otherwise be read
        // as a reply to whatever we last asked them. Kept off the queue so an
        // opt-out can't sit behind a backed-up worker.
        if ($employee !== null && $isOptOut) {
            $this->optOutTechnician($employee, $data['body'] ?? null);
        } elseif ($this->belongsToLiveNegotiation($employee, $customer)) {
            ProcessAiSchedulingReply::dispatch($inbound->id)->afterCommit();
        }

        $inbound->update(['processed' => true]);

        return $inbound;
    }

    /**
     * Resolve a sender against the crew. Employee.phone is free text (it has
     * never been normalized on write), so an exact match is tried first and a
     * normalized scan second — the employee table is small enough that this
     * is cheaper than backfilling every historical row.
     */
    public static function matchEmployee(string $fromE164): ?Employee
    {
        $exact = Employee::where('phone', $fromE164)->first();

        if ($exact !== null) {
            return $exact;
        }

        return Employee::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get()
            ->first(fn (Employee $e) => PhoneNumber::toE164($e->phone) === $fromE164);
    }

    /**
     * Worth waking the AI scheduling worker for? Deliberately NOT gated on
     * the global ai_scheduling_enabled setting: that switch stops new
     * sessions from being started, but a session already in flight when it is
     * turned off still needs its replies read, or it silently wedges with a
     * technician waiting on an answer.
     */
    private function belongsToLiveNegotiation(?Employee $employee, ?Customer $customer): bool
    {
        if ($employee !== null) {
            return $employee->ai_scheduling_enabled || TechOutreach::liveAttemptFor($employee) !== null;
        }

        return $customer !== null && AiSchedulingService::liveSessionForCustomer($customer) !== null;
    }

    /**
     * A technician texting STOP opts out of AI scheduling outreach only. It
     * deliberately does NOT touch their operational texts (job reminders,
     * end-of-day summaries) — those are how they do their job, and nobody
     * texting "stop" to a robot means "stop telling me where to be tomorrow".
     */
    private function optOutTechnician(Employee $employee, ?string $body): void
    {
        if (! $employee->ai_scheduling_enabled) {
            return;
        }

        $employee->forceFill(['ai_scheduling_enabled' => false])->save();

        activity()->performedOn($employee)
            ->event('ai_scheduling_opt_out')
            ->withProperties(['text' => $body])
            ->log("{$employee->name} opted out of AI scheduling texts");
    }
}
