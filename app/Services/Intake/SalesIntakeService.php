<?php

namespace App\Services\Intake;

use App\Enums\ContactPref;
use App\Enums\TimePreference;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\Setting;
use App\Services\JobLifecycle;
use App\Services\Podium\PodiumThreadReader;
use App\Services\Telegram\NewJobBookedAlert;
use App\Support\GlassPosition;
use App\Support\RequestedDay;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Receives a booked lead pushed in by the sales dashboard
 * (POST /webhooks/sales-intake) and turns it into an ordinary ops job.
 *
 * The contract lives in the shared "Sales -> Dispatch Intake Contract (v1)".
 * Everything here is deliberately a thin adapter: the payload is validated,
 * mapped onto the SAME data array manual intake builds, and handed to
 * JobLifecycle::createFromIntake(). No second creation path exists, so a lead
 * that arrives over the wire behaves exactly like one a dispatcher typed in.
 *
 * Idempotency is the (external_source, external_ref) unique index on `jobs` —
 * the sender may retry a push forever and only one job can ever exist for a
 * given sales lead id. The controller answers 200 "duplicate" on a repeat.
 *
 * What happens AFTER creation is the one toggle: `sales_auto_intake_enabled`.
 * Off (the default), the job simply waits on the Send-welcome screen exactly
 * like a manual lead. On, we make the choice a dispatcher would have made there
 * by looking for a Podium conversation with the customer.
 */
class SalesIntakeService
{
    /** The only sender we expect; still taken from the payload so a second one can be added. */
    public const SOURCE = 'glass-sales-dashboard';

    public const SYSTEM_ACTOR_EMAIL = 'sales-dashboard@system.local';

    public const SYSTEM_ACTOR_NAME = 'Sales Dashboard';

    private ?Employee $systemActor = null;

    public function __construct(
        private JobLifecycle $lifecycle,
        private PodiumThreadReader $podium,
        private NewJobBookedAlert $alert,
    ) {}

    /** Auto-intake toggle (Admin > Integrations > Sales dashboard). Default OFF. */
    public static function autoIntakeEnabled(): bool
    {
        return Setting::get('sales_auto_intake_enabled', '0') === '1';
    }

    /**
     * Validate the contract payload. Throws ValidationException, which the
     * controller renders as the contract's 422 {"errors": {...}}.
     *
     * An unknown or deactivated entity_code is a validation error, not a 404 —
     * the sender's entity map is the thing that's wrong and a rep has to fix it.
     *
     * @throws ValidationException
     */
    public function validate(array $payload): array
    {
        $validator = Validator::make($payload, [
            'external_source' => ['required', 'string', 'max:100'],
            'external_ref' => ['required', 'string', 'max:100'],
            'is_test' => ['nullable', 'boolean'],
            'booked_at' => ['nullable', 'date'],
            'entity_code' => ['required', 'string', 'max:32'],

            'customer' => ['required', 'array'],
            'customer.first_name' => ['required', 'string', 'max:120'],
            'customer.last_name' => ['nullable', 'string', 'max:120'],
            // The sender normalizes to E.164 and refuses to push when it can't.
            'customer.phone_e164' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'customer.service_address' => ['required', 'string', 'max:500'],
            'customer.city' => ['nullable', 'string', 'max:120'],

            'vehicle' => ['nullable', 'array'],
            'vehicle.year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'vehicle.make' => ['nullable', 'string', 'max:60'],
            'vehicle.model' => ['nullable', 'string', 'max:60'],
            'vehicle.raw' => ['nullable', 'string', 'max:255'],

            // The ops vocabulary is the source of truth; the sender copies it.
            'glass_position' => ['nullable', 'string', Rule::in(GlassPosition::OPTIONS)],
            'part_number' => ['nullable', 'string', 'max:60'],
            'part_available' => ['nullable', 'string', Rule::in(['yes', 'no', 'na'])],
            'quote_amount_cents' => ['nullable', 'integer', 'min:0'],
            'is_insurance' => ['nullable', 'boolean'],
            'insurance_carrier' => ['nullable', 'string', 'max:120'],

            'requested_day' => ['nullable', 'date_format:Y-m-d'],
            'requested_time_preference' => ['nullable', 'string', Rule::in(array_column(TimePreference::cases(), 'value'))],
            // The hour behind the "after" preference. Nullable so a sender that
            // predates it still validates — an After with no hour simply reads
            // as a flexible day here, never a 422.
            'requested_after_time' => ['nullable', 'string', 'date_format:H:i'],
            'requested_time' => ['nullable', 'string', 'max:120'],
            'requested_daytime_raw' => ['nullable', 'string', 'max:190'],
            'contact_pref' => ['nullable', 'string', Rule::in(['sms', 'call'])],
            'notes' => ['nullable', 'string', 'max:5000'],

            'salesperson' => ['required', 'array'],
            'salesperson.name' => ['required', 'string', 'max:120'],
            'salesperson.phone' => ['nullable', 'string', 'max:32'],
        ]);

        $validator->after(function ($validator) use ($payload) {
            $code = $payload['entity_code'] ?? null;

            if (is_string($code) && trim($code) !== '' && $this->resolveEntity($code) === null) {
                $validator->errors()->add(
                    'entity_code',
                    'Unknown or inactive entity code ['.$code.'].',
                );
            }
        });

        return $validator->validate();
    }

    /** An active ops entity for this code, or null (case-insensitive: "delmar" == "DelMar"). */
    public function resolveEntity(string $code): ?Entity
    {
        return Entity::query()
            ->whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])
            ->where('active', true)
            ->first();
    }

    /** The job already created for this (source, ref) pair, if any. */
    public function existingJob(string $source, string $ref): ?Job
    {
        return Job::withTrashed()
            ->where('external_source', $source)
            ->where('external_ref', $ref)
            ->first();
    }

    /**
     * Create the job from a VALIDATED payload. Nothing is sent here — the caller
     * runs runAutomation() once the row exists.
     */
    public function create(array $payload): Job
    {
        $entity = $this->resolveEntity((string) $payload['entity_code']);
        $actor = $this->systemActor();

        $job = $this->lifecycle->createFromIntake(
            $this->toIntakeData($payload, $entity),
            $actor,
        );

        activity()->performedOn($job)->causedBy($actor)
            ->event('lead_created_sales_intake')
            ->log('Lead created from sales dashboard (lead #'.$payload['external_ref'].')');

        return $job;
    }

    /**
     * Map the contract payload onto the intake data array
     * JobLifecycle::createFromIntake() expects.
     */
    private function toIntakeData(array $payload, Entity $entity): array
    {
        $requestedDay = $payload['requested_day'] ?? null;
        $requestedTime = $this->nullIfBlank($payload['requested_time'] ?? null);
        $preference = $payload['requested_time_preference'] ?? TimePreference::None->value;
        // Only the After case owns an hour; anything else arriving with one is
        // dropped rather than stored against a preference that ignores it.
        $afterTime = $preference === TimePreference::After->value
            ? $this->nullIfBlank($payload['requested_after_time'] ?? null)
            : null;

        return [
            'entity_id' => $entity->id,

            'phone_e164' => $payload['customer']['phone_e164'],
            'first_name' => $payload['customer']['first_name'],
            'last_name' => $this->nullIfBlank($payload['customer']['last_name'] ?? null),
            'service_address' => $payload['customer']['service_address'],
            'city' => $this->nullIfBlank($payload['customer']['city'] ?? null),
            'contact_pref' => $this->contactPref($payload['contact_pref'] ?? null),

            'vehicle_year' => isset($payload['vehicle']['year']) ? (string) $payload['vehicle']['year'] : null,
            'vehicle_make' => $this->nullIfBlank($payload['vehicle']['make'] ?? null),
            'vehicle_model' => $this->nullIfBlank($payload['vehicle']['model'] ?? null),
            'vehicle_raw' => $this->nullIfBlank($payload['vehicle']['raw'] ?? null),

            'glass_position' => $this->nullIfBlank($payload['glass_position'] ?? null),
            // Left as the legacy single part: the sales dashboard quotes one
            // part and sends no cost, so there is nothing to put in parts_json
            // (Job::partLabels()/PartsOrderLinks both fall back to part_number).
            'part_number' => $this->nullIfBlank($payload['part_number'] ?? null),
            'part_available' => $this->partAvailable($payload['part_available'] ?? null),

            'quote_amount_cents' => $payload['quote_amount_cents'] ?? null,
            'is_insurance' => (bool) ($payload['is_insurance'] ?? false),
            'insurance_carrier' => $this->nullIfBlank($payload['insurance_carrier'] ?? null),

            'requested_day' => $requestedDay,
            'requested_time' => $requestedTime,
            'requested_time_preference' => $preference,
            'requested_after_time' => $afterTime,
            // Prefer what the rep actually saw; otherwise compose the same
            // "Monday, July 21 Morning" string manual intake writes.
            'requested_daytime_raw' => $this->nullIfBlank($payload['requested_daytime_raw'] ?? null)
                ?? $this->composeRequestedDaytime($requestedDay, $requestedTime, $preference, $afterTime),

            'notes' => $this->nullIfBlank($payload['notes'] ?? null),

            // The booking rep is a sales-dashboard user, not an ops employee —
            // snapshot the name/phone and leave booking_salesperson_id null.
            'booking_salesperson_id' => null,
            'booking_salesperson_name' => $payload['salesperson']['name'],
            'booking_salesperson_phone' => $this->nullIfBlank($payload['salesperson']['phone'] ?? null),

            'external_source' => $payload['external_source'],
            'external_ref' => (string) $payload['external_ref'],
            'is_test' => (bool) ($payload['is_test'] ?? false),

            'parse_status' => 'clean',
            // The push verbatim. Keeps the facts that have no column of their
            // own (booked_at, contact_pref, the vehicle split) readable on the
            // job, which is the only record of what sales actually sent.
            'parsed_payload_json' => $payload,
        ];
    }

    /**
     * The welcome + alert side of the contract, run once the job exists.
     *
     * Never throws: a job that made it into the database must be answered for
     * with a 201, whatever Podium or Telegram do afterwards. A failed Podium
     * read falls back to the OFF behaviour (the job waits on the Send-welcome
     * screen) rather than guessing at the customer's first impression.
     */
    public function runAutomation(Job $job): void
    {
        $autoIntake = self::autoIntakeEnabled();

        if ($autoIntake) {
            try {
                $this->autoSendWelcome($job);
            } catch (\Throwable $e) {
                Log::error('SalesIntake: auto welcome failed, job left for manual welcome.', [
                    'job_id' => $job->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->alert->sendFor($job, autoIntakeEnabled: $autoIntake);
        } catch (\Throwable $e) {
            Log::warning('SalesIntake: Telegram alert failed.', [
                'job_id' => $job->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Make the Send-welcome choice for the dispatcher:
     *  - a Podium conversation exists -> "Podium handoff doesn't exist" button
     *    (salesAcknowledged: false): handoff from the Podium thread now, Quo
     *    dispatch intro ~45s later.
     *  - no conversation -> "CX not in Podium" button (salesAcknowledged: true):
     *    the Quo dispatch intro only.
     */
    private function autoSendWelcome(Job $job): void
    {
        // A test lead never texts a real customer, whatever the toggles say.
        if ($job->is_test) {
            Log::info('SalesIntake: test job, welcome skipped.', ['job_id' => $job->id]);

            return;
        }

        try {
            $hasConversation = $this->podium->latestFor($job->customer?->phone_e164, 5) !== [];
        } catch (\Throwable $e) {
            // Fall back to limbo: the job waits on /admin/send-welcome?job=ID.
            Log::warning('SalesIntake: Podium check failed, leaving job for manual welcome.', [
                'job_id' => $job->id, 'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->lifecycle->sendWelcome($job, salesAcknowledged: ! $hasConversation, actor: $this->systemActor());
    }

    /**
     * The employee row auto-intaken jobs are created by. Seeded by migration
     * 2026_07_31_120200; re-created here if it ever goes missing so the webhook
     * can never fail on a missing actor.
     */
    public function systemActor(): Employee
    {
        return $this->systemActor ??= Employee::withTrashed()->firstOrCreate(
            ['email' => self::SYSTEM_ACTOR_EMAIL],
            [
                'name' => self::SYSTEM_ACTOR_NAME,
                'job_functions' => [],
                'can_login' => false,
                'active' => false,
            ],
        );
    }

    /** "yes"/"no" -> the nullable boolean column; "na" and null both mean "not answered". */
    private function partAvailable(?string $value): ?bool
    {
        return match ($value) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }

    /** The contract's sms|call onto the customer's ContactPref (text|call|both). */
    private function contactPref(?string $value): ?string
    {
        return match ($value) {
            'sms' => ContactPref::Text->value,
            'call' => ContactPref::Call->value,
            default => null,
        };
    }

    /**
     * The same "Monday, July 21 Morning" string JobForm::syncRequestedDaytime
     * writes at manual intake — the day spelled out, then the specific clock
     * time if we were given one, else the preference's own short wording.
     * Feeds the {preferred_date_time} merge var.
     */
    private function composeRequestedDaytime(?string $day, ?string $time, ?string $preference, ?string $afterTime = null): ?string
    {
        $case = TimePreference::tryFrom((string) $preference);

        $timeText = $case === TimePreference::After
            ? $case->shortLabel($afterTime)
            : ($time ?? ($case?->shortLabel() ?? ''));

        $composed = trim(trim((string) RequestedDay::spellOut($day)).' '.$timeText);

        return $composed === '' ? null : $composed;
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
