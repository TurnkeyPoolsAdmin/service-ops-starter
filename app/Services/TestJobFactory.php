<?php

namespace App\Services;

use App\Enums\JobFunction;
use App\Enums\TimePreference;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Support\Timezone;

/**
 * Builds a throwaway "test job" in one click (Admin > Settings > "Load a test
 * job"), so a new feature can be exercised end-to-end without inventing a fake
 * customer by hand every time.
 *
 * Everything on it is an obvious placeholder EXCEPT the phone, which is always
 * the real tester's line (555-555-0142) - the whole point is that the job runs
 * the ORDINARY lifecycle and its texts really send, to a number you can read.
 * It goes in as a normal pending-schedule lead, and the caller sends it on to
 * the SendWelcome screen exactly like intake does, so even the welcome text is
 * part of what you're testing.
 *
 * It's flagged `is_test`, which only changes one thing: deleting it purges it
 * outright instead of parking it in the super-admin Issues queue.
 */
class TestJobFactory
{
    /** The tester's phone. Deliberately constant so repeat clicks reuse one customer. */
    public const PHONE = '+15555550142';

    public function __construct(private JobLifecycle $lifecycle) {}

    /** Create (and return) a fresh test job for the given actor. */
    public function create(Employee $actor): Job
    {
        return $this->lifecycle->createFromIntake($this->data(), $actor);
    }

    /**
     * The intake payload. Mirrors what Intake::confirm() assembles, with
     * placeholder values in every human-readable field.
     */
    private function data(): array
    {
        $today = Timezone::now()->toDateString();

        return [
            'entity_id' => $this->entityId(),
            'phone_e164' => self::PHONE,
            'first_name' => 'Test',
            'last_name' => 'Placeholder',
            'service_address' => '123 Test Street, Test City, CA 90001',
            'city' => 'Test City',
            'vehicle_year' => '2020',
            'vehicle_make' => 'Testmake',
            'vehicle_model' => 'Testmodel',
            'vehicle_raw' => '2020 Testmake Testmodel',
            'vin' => 'TESTVIN0000000001',
            'glass_position' => 'Windshield',
            'part_number' => 'TEST-PART-001',
            'parts_json' => [['label' => 'TEST-PART-001', 'cost_cents' => 10000]],
            'part_cost_cents' => 10000,
            'quote_amount_cents' => 30000,
            'is_insurance' => false,
            'needs_calibration' => false,
            'booking_salesperson_id' => $this->salespersonId(),
            'requested_day' => $today,
            'requested_time' => 'Test placeholder time',
            'requested_time_preference' => TimePreference::None->value,
            'requested_daytime_raw' => 'Test placeholder day/time',
            'notes' => 'TEST JOB - placeholder data, safe to delete.',
            'parse_status' => 'clean',
            'is_test' => true,
        ];
    }

    /** Prefer LA so the job lands somewhere predictable; fall back to whatever exists. */
    private function entityId(): int
    {
        $entity = Entity::where('code', 'LA')->first() ?? Entity::orderBy('id')->firstOrFail();

        return $entity->id;
    }

    /** Any active sales rep — the field is required at intake, but not for a test row. */
    private function salespersonId(): ?int
    {
        return Employee::active()->withJobFunction(JobFunction::Sales)->orderBy('name')->value('id');
    }
}
