<?php

namespace App\Services\Parsing;

use App\Enums\ParseStatus;

/**
 * Structured result of parsing a WhatsApp lead. Field values are best-effort;
 * the operator confirms/corrects them in the intake preview before a job is
 * created. Unknown input is captured as warnings, never silently dropped.
 */
class ParsedLead
{
    public ?string $city = null;

    public ?string $service_address = null;

    public ?string $first_name = null;

    public ?string $last_name = null;

    public ?string $phone_e164 = null;

    public ?string $phone_raw = null;

    public ?string $vehicle_year = null;

    public ?string $vehicle_make = null;

    public ?string $vehicle_model = null;

    public ?string $vin = null;

    public ?string $glass_position = null;

    public ?string $vehicle_raw = null;

    /**
     * All parts on the lead (windshield + any extras like molding/clips).
     * The first entry is the primary part. `part_number`/`part_cost_cents`
     * below are derived from this list (first label; summed cost).
     *
     * @var array<int, array{label: ?string, cost_cents: ?int}>
     */
    public array $parts = [];

    public ?string $part_number = null;

    public ?int $part_cost_cents = null;

    public ?int $quote_amount_cents = null;

    public ?string $requested_daytime_raw = null;

    public ?string $requested_day = null;    // Today | Tomorrow | a specific date (as written)

    public ?string $requested_date = null;   // that day resolved to a real Y-m-d (null if it couldn't be)

    public ?string $requested_time = null;   // a specific time | As soon as possible | Morning...

    /** That time read onto a TimePreference case (null when the text said nothing about time). */
    public ?string $requested_time_preference = null;

    /** The hour an "after 10am" request starts from, 'H:i'. Only ever set for the After case. */
    public ?string $requested_after_time = null;

    public ?string $entity_code = null;      // LA | SFV | DelMar

    public ?string $notes = null;            // free-form operator/tech notes

    /** @var string[] */
    public array $warnings = [];

    /** @var string[] Lines that could not be matched to a known label. */
    public array $unknown_lines = [];

    public string $raw = '';

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function status(): ParseStatus
    {
        // Garbage in: nothing useful extracted.
        if ($this->phone_e164 === null && $this->first_name === null && $this->vin === null && $this->vehicle_raw === null) {
            return ParseStatus::Failed;
        }

        return empty($this->warnings) ? ParseStatus::Clean : ParseStatus::Warnings;
    }

    /** The structured payload persisted to jobs.parsed_payload_json. */
    public function toPayload(): array
    {
        return [
            'city' => $this->city,
            'service_address' => $this->service_address,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone_e164' => $this->phone_e164,
            'phone_raw' => $this->phone_raw,
            'vehicle_year' => $this->vehicle_year,
            'vehicle_make' => $this->vehicle_make,
            'vehicle_model' => $this->vehicle_model,
            'vin' => $this->vin,
            'glass_position' => $this->glass_position,
            'vehicle_raw' => $this->vehicle_raw,
            'parts' => $this->parts,
            'part_number' => $this->part_number,
            'part_cost_cents' => $this->part_cost_cents,
            'quote_amount_cents' => $this->quote_amount_cents,
            'requested_daytime_raw' => $this->requested_daytime_raw,
            'requested_day' => $this->requested_day,
            'requested_date' => $this->requested_date,
            'requested_time' => $this->requested_time,
            'requested_time_preference' => $this->requested_time_preference,
            'requested_after_time' => $this->requested_after_time,
            'entity_code' => $this->entity_code,
            'notes' => $this->notes,
            'unknown_lines' => $this->unknown_lines,
        ];
    }
}
