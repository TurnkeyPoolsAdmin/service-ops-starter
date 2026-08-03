<?php

namespace App\Services\Parts;

use App\Enums\PartsSupplier;
use App\Models\Job;
use App\Models\Warehouse;

/**
 * Builds direct supplier order-search links for a job's parts. Pure/stateless
 * so it's trivially unit-testable — no DB writes, no side effects.
 */
class PartsOrderLinks
{
    /**
     * One entry per part on the job: ['part' => label, 'url' => ?string, 'note' => ?string].
     * Falls back to the legacy single part_number when parts_json is empty.
     *
     * $warehouseOverride lets the order-parts modal preview links against a
     * warehouse just picked in the form, before it's saved to the job.
     *
     * @return array<int, array{part: ?string, url: ?string, note: ?string}>
     */
    public function linksForJob(Job $job, ?Warehouse $warehouseOverride = null): array
    {
        $parts = $job->parts_json ?: [];
        if ($parts === [] && filled($job->part_number)) {
            $parts = [['label' => $job->part_number]];
        }
        if ($parts === []) {
            $parts = [['label' => null]];
        }

        $warehouse = $warehouseOverride ?? $job->warehouse;

        return array_map(
            fn (array $part) => $this->linkForPart($part['label'] ?? null, $warehouse),
            $parts,
        );
    }

    /** @return array{part: ?string, url: ?string, note: ?string} */
    private function linkForPart(?string $label, $warehouse): array
    {
        $token = $this->firstToken($label);

        if ($token === null) {
            return ['part' => $label, 'url' => null, 'note' => 'No part number on the job'];
        }

        if ($warehouse === null || $warehouse->supplier === null) {
            return ['part' => $token, 'url' => null, 'note' => 'Please order this part — dealer / custom supplier (no direct link).'];
        }

        return match ($warehouse->supplier) {
            PartsSupplier::Mygrant => $this->mygrantLink($token, $warehouse->supplier_code),
            PartsSupplier::Igc => $this->igcLink($token),
        };
    }

    /** @return array{part: string, url: ?string, note: ?string} */
    private function mygrantLink(string $token, ?string $code): array
    {
        if (blank($code)) {
            return ['part' => $token, 'url' => null, 'note' => 'Set the Mygrant warehouse code in Admin → Warehouses'];
        }

        $url = sprintf(
            config('glass.parts_ordering.mygrant_url'),
            rawurlencode($token),
            rawurlencode($code),
        );

        return ['part' => $token, 'url' => $url, 'note' => null];
    }

    /** @return array{part: string, url: ?string, note: ?string} */
    private function igcLink(string $token): array
    {
        if (! preg_match('/^([A-Za-z]+\d+)/', strtoupper($token), $m)) {
            return ['part' => $token, 'url' => null, 'note' => "Couldn't parse part # — search IGC manually"];
        }

        $url = sprintf(config('glass.parts_ordering.igc_url'), rawurlencode($m[1]));

        return ['part' => $token, 'url' => $url, 'note' => null];
    }

    /** First whitespace-delimited token of the "Part # / description" label. */
    private function firstToken(?string $label): ?string
    {
        $trimmed = trim((string) $label);
        if ($trimmed === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $trimmed);

        return $parts[0] ?? null;
    }
}
