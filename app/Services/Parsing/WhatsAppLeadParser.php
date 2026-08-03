<?php

namespace App\Services\Parsing;

use App\Enums\TimePreference;
use App\Support\Money;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;

/**
 * Deterministic (no-LLM) parser for the semi-structured WhatsApp lead messages
 * sales reps send to the office. Tolerant of label aliases, reordering, blank
 * lines, and missing fields. Conservative on make/model splitting — prefers
 * leaving the raw vehicle description intact over guessing wrong.
 */
class WhatsAppLeadParser
{
    /**
     * Canonical field => normalized label aliases. Labels are normalized by
     * lowercasing and collapsing any run of non-alphanumerics to a single space.
     */
    private const ALIASES = [
        'city' => ['city'],
        'service_address' => ['address', 'service address', 'addr', 'street address', 'cust location', 'customer location', 'service location', 'cust address', 'customer address', 'cust addr'],
        'name' => ['name', 'customer', 'customer name'],
        'phone' => ['phone', 'phone number', 'cell', 'mobile', 'tel'],
        'vehicle' => ['vehicle', 'car', 'auto'],
        'part_number' => ['part', 'part number', 'part no', 'part num', 'partno'],
        'part_available' => ['part available', 'part availability', 'available', 'availability', 'in stock'],
        'quote' => ['quote', 'quote amount', 'price', 'total', 'part price quote provided', 'part price quote', 'quote provided'],
        'requested_daytime' => ['req day time', 'requested time', 'requested day time', 'day time', 'requested', 'req time', 'request for a time window', 'request for time window', 'time window', 'requested window'],
        'contact_pref' => ['text call pref', 'contact pref', 'pref', 'call text pref', 'preference'],
        'entity' => ['entity', 'location', 'company', 'shop'],
        'notes' => ['notes', 'note', 'comments', 'comment', 'remarks', 'special instructions', 'instructions', 'additional note', 'additional notes', 'addional note'],
        'glass_position' => ['job type', 'glass', 'glass position', 'glass type'],
    ];

    /** Known multi-word makes checked before single-token make detection. */
    private const MULTIWORD_MAKES = [
        'land rover', 'alfa romeo', 'aston martin', 'mercedes benz',
    ];

    /** Single-token makes we can confidently pull off the front of a description. */
    private const SINGLE_MAKES = [
        'tesla', 'honda', 'toyota', 'ford', 'chevy', 'chevrolet', 'nissan', 'bmw',
        'audi', 'kia', 'hyundai', 'subaru', 'mazda', 'jeep', 'gmc', 'dodge', 'ram',
        'lexus', 'acura', 'volkswagen', 'vw', 'volvo', 'mercedes', 'porsche',
        'mini', 'mitsubishi', 'buick', 'cadillac', 'chrysler', 'lincoln', 'infiniti',
        'jaguar', 'fiat', 'genesis', 'rivian', 'lucid',
    ];

    public function parse(string $text): ParsedLead
    {
        $lead = new ParsedLead;
        $lead->raw = $text;

        // Track the last recognized field so unlabeled continuation lines (e.g. a
        // second part under a "Part#:" line) can be attributed to it.
        $context = null;
        foreach ($this->splitLines($text) as $line) {
            $context = $this->parseLine($line, $lead, $context);
        }

        $this->finalizeParts($lead);
        $this->deriveCity($lead);
        $this->applyNoteTimeConstraint($lead);
        $this->finalizeWarnings($lead);

        return $lead;
    }

    /** Normalize line endings, trim, strip blank/emoji-only/junk lines. */
    private function splitLines(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $out = [];

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Drop lines that are only emoji/symbols (no letters or digits).
            if (! preg_match('/[A-Za-z0-9]/', $line)) {
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * Parse one line into $lead and return the canonical field it set (the new
     * "context"), or null. The returned context lets the caller attribute an
     * unlabeled follow-up line (a continuation) to the right field.
     */
    private function parseLine(string $line, ParsedLead $lead, ?string $context): ?string
    {
        // A label line has "Label: value". Split on the FIRST colon only.
        if (! str_contains($line, ':')) {
            // Continuation of a multi-line "Notes:" block — once we're in notes
            // context, unlabeled lines belong to the note until a new labeled
            // line appears (notes are conventionally last in the message).
            if ($context === 'notes') {
                $this->appendNote($line, $lead);

                return 'notes';
            }

            // Header-style entity hint, e.g. "LA Reference" / "Del Mar Reference".
            if ($this->tryEntityReference($line, $lead)) {
                return 'entity';
            }

            // An unlabeled street address (rep forgot the "Address:" label).
            if ($this->tryAddress($line, $lead)) {
                return 'service_address';
            }

            // A continuation of the parts list: an unlabeled line with a price,
            // right under a "Part#:" line (additional part such as a molding).
            if ($context === 'part_number' && $this->looksLikePartLine($line)) {
                $this->applyPart($line, $lead);

                return 'part_number';
            }

            // A standalone VIN line the rep sent separately from the vehicle,
            // often with a dash instead of a colon (e.g. "VIN- 4T3T6RFV0PU000124").
            if ($this->tryVin($line, $lead)) {
                return 'vin';
            }

            $lead->unknown_lines[] = $line;

            return null;
        }

        [$label, $value] = explode(':', $line, 2);
        $value = trim($value);
        $canonical = $this->matchLabel($label);

        if ($canonical === null) {
            // A labeled VIN line ("VIN: 4T3T6RFV0PU000124") — "vin" isn't a field
            // alias, so capture the token before it falls through as unknown.
            if ($this->tryVin($line, $lead)) {
                return 'vin';
            }

            // An unknown label whose VALUE is shaped like a street address
            // ("Cust spot: 220 N Toluca St Los Angeles, CA 90026") — reps invent
            // address labels faster than we can alias them.
            if ($this->tryAddress($value, $lead)) {
                return 'service_address';
            }

            $lead->unknown_lines[] = $line;

            return null;
        }

        // "Part available:"-family lines may carry a part+price instead of a
        // yes/no (one rep's template) — route those to the part parser, keeping
        // the part-continuation context.
        if ($canonical === 'part_available') {
            return $this->applyPartAvailability($value, $lead);
        }

        // Empty values are allowed (optional fields left blank).
        match ($canonical) {
            'city' => $lead->city = $this->nullIfEmpty($value),
            'service_address' => $lead->service_address = $this->normalizeAddress($value),
            'name' => $this->applyName($value, $lead),
            'phone' => $this->applyPhone($value, $lead),
            'vehicle' => $this->applyVehicle($value, $lead),
            'part_number' => $this->applyPart($value, $lead),
            'quote' => $lead->quote_amount_cents = Money::parseToCents($value),
            'requested_daytime' => $this->applyRequestedDaytime($value, $lead),
            // Contact preference is recognized only so its line is consumed
            // silently (no warning) — we no longer store it.
            'contact_pref' => null,
            'entity' => $lead->entity_code = $this->parseEntity($value),
            'notes' => $this->appendNote($value, $lead),
            'glass_position' => $lead->glass_position = $this->normalizeGlassPosition($value) ?? $lead->glass_position,
            default => null,
        };

        return $canonical;
    }

    /**
     * A "Part available:" line is normally yes/no, but some reps put the part
     * number + price under a "Part availability:" label instead. We no longer
     * track availability, so a boolish value is discarded; only a part-shaped
     * value is captured (routed to the part parser). Returns the part context so
     * an unlabeled follow-up part line still chains correctly.
     */
    private function applyPartAvailability(string $value, ParsedLead $lead): ?string
    {
        if ($this->parseBoolish($value) === null && $this->looksLikePartLine($value)) {
            $this->applyPart($value, $lead);

            return 'part_number';
        }

        return null;
    }

    /**
     * Recognize a header-style entity reference like "LA Reference" or
     * "Del Mar Reference" (no colon). Only accepts a value that resolves to a
     * known entity code, so arbitrary text still falls through as unknown.
     * Some reps send BOTH this and an "Entity:" line — we simply parse one.
     */
    private function tryEntityReference(string $line, ParsedLead $lead): bool
    {
        if (! preg_match('/^([A-Za-z .]+?)\s+reference\.?$/i', trim($line), $m)) {
            return false;
        }

        $code = $this->parseEntity($m[1]);
        if (! in_array($code, ['LA', 'SFV', 'DelMar'], true)) {
            return false;
        }

        $lead->entity_code = $code;

        return true;
    }

    /**
     * Recognize an unlabeled US street address so reps can omit the "Address:"
     * label (e.g. "17043 Gresham St Northridge, CA 91325"). Conservative: requires
     * a leading house number plus at least two of {street-type word, ", ST",
     * ZIP code}, so it won't swallow vehicle/part lines. Only fills service_address
     * when a labeled Address hasn't already set it. Returns true if applied.
     */
    private function tryAddress(string $line, ParsedLead $lead): bool
    {
        if ($lead->service_address !== null) {
            return false;
        }
        if (! $this->looksLikeAddress($line)) {
            return false;
        }

        $lead->service_address = $this->normalizeAddress($line);

        return true;
    }

    /**
     * Clean an address value: collapse whitespace and re-insert the space that
     * copy/paste from Google Maps drops between the street suffix and the city
     * ("220 N Toluca StLos Angeles" → "220 N Toluca St Los Angeles"). Only
     * splits when a known suffix is jammed against a capitalized word.
     */
    private function normalizeAddress(string $value): ?string
    {
        $value = $this->collapseSpaces($value);
        if ($value === '') {
            return null;
        }

        $suffixes = implode('|', array_map(
            fn ($s) => preg_quote($s, '/'),
            self::STREET_SUFFIXES,
        ));

        // Case-insensitive on the suffix ONLY — the lookahead must stay
        // case-sensitive or "Playa" splits at "Pl".
        return preg_replace('/\b((?i:'.$suffixes.'))(?=[A-Z][a-z])/', '$1 ', $value);
    }

    /**
     * Capture a standalone VIN line — one a rep sent separately from the
     * "Vehicle:" line, with or without a colon ("VIN- <vin>", "VIN: <vin>", or
     * a bare VIN). Pulls the first 17-char VIN-like token (no I, O, Q) and only
     * assigns it when we don't already have a VIN. Returns true if one was set.
     */
    private function tryVin(string $line, ParsedLead $lead): bool
    {
        if ($lead->vin !== null) {
            return false;
        }
        if (! preg_match('/\b([A-HJ-NPR-Z0-9]{17})\b/i', $line, $m)) {
            return false;
        }

        $lead->vin = strtoupper($m[1]);

        return true;
    }

    /** Street-type words that signal an address. */
    private const STREET_SUFFIXES = [
        'st', 'street', 'ave', 'avenue', 'blvd', 'boulevard', 'rd', 'road',
        'dr', 'drive', 'ln', 'lane', 'way', 'ct', 'court', 'pl', 'place',
        'ter', 'terrace', 'cir', 'circle', 'hwy', 'highway', 'pkwy', 'parkway',
        'sq', 'square', 'trl', 'trail', 'loop', 'pike', 'aly', 'alley', 'row',
        'walk', 'path', 'run', 'pass', 'plaza', 'cres', 'crescent',
    ];

    private function looksLikeAddress(string $line): bool
    {
        $line = trim($line);

        // Must start with a house number, and must not be a money/part line.
        if (! preg_match('/^\d{1,6}\s+\S/', $line) || str_contains($line, '$')) {
            return false;
        }

        $signals = 0;
        // 5-digit ZIP (optionally ZIP+4), as a standalone token.
        $signals += preg_match('/\b\d{5}(?:-\d{4})?\b/', $line) ? 1 : 0;
        // ", ST" two-letter state token (e.g. ", CA").
        $signals += preg_match('/,\s*[A-Za-z]{2}\b/', $line) ? 1 : 0;

        $lower = strtolower($line);
        foreach (self::STREET_SUFFIXES as $suffix) {
            if (preg_match('/\b'.preg_quote($suffix, '/').'\b/', $lower)) {
                $signals++;
                break;
            }
        }

        return $signals >= 2;
    }

    /** An unlabeled line that carries a price looks like an additional part. */
    private function looksLikePartLine(string $line): bool
    {
        return (bool) preg_match('/\$\s*[\d.,]+/', $line)
            || (bool) preg_match('/^\S.*\s+[\d.,]+$/', trim($line));
    }

    private function normalizeLabel(string $label): string
    {
        $n = strtolower(trim($label));
        $n = preg_replace('/[^a-z0-9]+/', ' ', $n);

        return trim($n);
    }

    private function matchLabel(string $label): ?string
    {
        $norm = $this->normalizeLabel($label);

        foreach (self::ALIASES as $canonical => $variants) {
            if (in_array($norm, $variants, true)) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * Append a line to the running notes block, preserving line breaks between
     * the "Notes:" line and any unlabeled continuation lines beneath it.
     */
    private function appendNote(string $value, ParsedLead $lead): void
    {
        $value = $this->collapseSpaces($value);
        if ($value === '') {
            return;
        }

        $lead->notes = $lead->notes === null ? $value : $lead->notes."\n".$value;
    }

    private function applyName(string $value, ParsedLead $lead): void
    {
        $value = $this->normalizeNameCase($this->collapseSpaces($value));
        if ($value === '') {
            return;
        }

        $parts = explode(' ', $value);
        $lead->first_name = array_shift($parts);

        // Drop one-letter middle initials (with or without a period) so a name
        // like "Christine E Bezdeny" resolves its last name to "Bezdeny", not
        // "E Bezdeny". Only strip when a real (multi-character) surname token
        // still remains — otherwise keep the original tokens.
        $surname = array_values(array_filter(
            $parts,
            fn ($p) => ! preg_match('/^[A-Za-z]\.?$/', $p),
        ));
        if ($surname === []) {
            $surname = $parts;
        }

        $lead->last_name = $surname ? implode(' ', $surname) : null;
    }

    /**
     * Reps sometimes send the name in ALL CAPS ("MELISSA GOMEZ"). Only when the
     * ENTIRE value is uppercase, title-case each letter run — mixed-case input
     * is left exactly as typed. Runs split on apostrophes/hyphens, so
     * "O'BRIEN" → "O'Brien" and "MARY-JANE" → "Mary-Jane"; single-letter runs
     * (middle initials) are untouched.
     */
    private function normalizeNameCase(string $value): string
    {
        if ($value === '' || preg_match('/[a-z]/', $value)) {
            return $value;
        }

        return preg_replace_callback(
            '/[A-Z]{2,}/',
            fn ($m) => ucfirst(strtolower($m[0])),
            $value,
        );
    }

    private function applyPhone(string $value, ParsedLead $lead): void
    {
        $lead->phone_raw = $this->nullIfEmpty($value);
        if ($lead->phone_raw === null) {
            return;
        }

        $lead->phone_e164 = PhoneNumber::toE164($value);
    }

    private function applyVehicle(string $value, ParsedLead $lead): void
    {
        $value = $this->collapseSpaces($value);
        if ($value === '') {
            return;
        }

        $lead->vehicle_raw = $value;

        // Anchor on a 17-char VIN-like token (no I, O, Q).
        if (preg_match('/\b([A-HJ-NPR-Z0-9]{17})\b/i', $value, $m, PREG_OFFSET_CAPTURE)) {
            $vin = $m[1][0];
            $offset = $m[1][1];
            $lead->vin = strtoupper($vin);

            $before = trim(substr($value, 0, $offset));
            $after = trim(substr($value, $offset + strlen($vin)));

            // Strip a trailing "vin" label and any dangling separators left before
            // the VIN (e.g. "... C300 AMC - vin <VIN>" → "... C300 AMC").
            $before = trim(preg_replace('/[\s\-–—:#]*\bvin\b[\s\-–—:#]*$/i', '', $before));
            $before = trim(rtrim($before, '-–—:'));

            $this->splitVehicleDescription($before, $lead);
            // Don't clobber a position a labeled line (e.g. "Job type: WS")
            // already set when nothing follows the VIN.
            $lead->glass_position = $this->normalizeGlassPosition($after) ?? $lead->glass_position;
        } else {
            // No VIN: peel a trailing glass-position phrase ("R/R Door", "W/S",
            // "back glass") off the end first, so it isn't mistaken for part of
            // the model — then split the remaining year + make/model.
            [$desc, $position] = $this->extractGlassPosition($value);
            if ($position !== null) {
                $lead->glass_position = $this->normalizeGlassPosition($position);
            }
            $this->splitVehicleDescription($desc, $lead);
        }
    }

    /**
     * Split a trailing glass-position phrase off the end of a vehicle
     * description that carries no inline VIN. Anchors on a glass-type keyword
     * at the END of the string, optionally preceded by directional qualifiers
     * ("R/R", "front left", "LF"), so "2023 Subaru Crosstrek limited R/R Door"
     * yields ["2023 Subaru Crosstrek limited", "R/R Door"]. Returns
     * [vehicleDesc, glassPositionRaw]; the position is null when no glass
     * keyword is found.
     *
     * @return array{0: string, 1: ?string}
     */
    private function extractGlassPosition(string $value): array
    {
        $pattern = '/\s+('
            .'(?:(?:front|rear|left|right|driver|passenger|[fr]\s*\/\s*[lr]|[fr][lr])\s+)*'
            .'(?:windshield|windscreen|w\s*\/\s*s|back\s*glass|backlite|backlight|back\s*window'
            .'|quarter(?:\s+glass)?|vent(?:\s+glass)?|door(?:\s+glass)?|sunroof|back)'
            .')\s*$/i';

        if (! preg_match($pattern, $value, $m, PREG_OFFSET_CAPTURE)) {
            return [$value, null];
        }

        $desc = trim(substr($value, 0, $m[0][1]));

        return [$desc, $this->collapseSpaces($m[1][0])];
    }

    /** Best-effort, conservative year/make/model split. Always keeps vehicle_raw. */
    private function splitVehicleDescription(string $desc, ParsedLead $lead): void
    {
        $desc = $this->collapseSpaces($desc);
        if ($desc === '') {
            return;
        }

        // Extract the first 4-digit model year (1900-2099) anywhere in the string.
        if (preg_match('/\b(19|20)\d{2}\b/', $desc, $ym)) {
            $lead->vehicle_year = $ym[0];
            // Remove that year token from the description (first occurrence).
            $desc = $this->collapseSpaces(preg_replace('/\b'.preg_quote($ym[0], '/').'\b/', '', $desc, 1));
        }

        if ($desc === '') {
            return;
        }

        $lower = strtolower($desc);
        // Normalize hyphens to spaces for make MATCHING only (e.g. "Mercedes-Benz"
        // → "mercedes benz"). Replacement is 1-for-1 so offsets are preserved and
        // the make can still be sliced from the original $desc with its hyphen.
        $lowerNorm = str_replace('-', ' ', $lower);

        // Multi-word makes first. The make is the leading substring of $desc
        // (preserving the original casing); everything after it is the model.
        foreach (self::MULTIWORD_MAKES as $make) {
            if (str_starts_with($lowerNorm, $make.' ') || $lowerNorm === $make) {
                $lead->vehicle_make = trim(substr($desc, 0, strlen($make)));
                $lead->vehicle_model = $this->nullIfEmpty(trim(substr($desc, strlen($make))));

                return;
            }
        }

        // Single-token make at the front.
        $tokens = explode(' ', $desc);
        $firstLower = strtolower($tokens[0]);
        if (in_array($firstLower, self::SINGLE_MAKES, true)) {
            $lead->vehicle_make = $tokens[0];
            $lead->vehicle_model = count($tokens) > 1 ? implode(' ', array_slice($tokens, 1)) : null;

            return;
        }

        // Couldn't confidently identify a make. Leave make/model null; the raw
        // description and the (parse-time) warning let the operator decide.
        $lead->addWarning("Couldn't confidently split vehicle make/model from: \"{$desc}\". Left raw for review.");
    }

    /** Parse a part line ("FW04239 GTY FYG $155.00") onto the parts list. */
    private function applyPart(string $value, ParsedLead $lead): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        // One line can carry several parts (windshield + a moulding/clip), each
        // ending in a "$cost": "FW02994 GBN FYG $101.75  WFT F2994 PRP $11.72".
        // Split on each "<label> $<cost>" segment so extras aren't dropped.
        if (substr_count($value, '$') > 1
            && preg_match_all('/(.+?)\$\s*([\d.,]+)/', $value, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $this->pushPart($m[1], Money::parseToCents($m[2]), $lead);
            }

            return;
        }

        $label = $value;
        $cost = null;

        // Split the part label from a "$cost" suffix, or a trailing bare number.
        if (str_contains($value, '$')) {
            [$num, $rest] = explode('$', $value, 2);
            $label = trim($num);
            $cost = Money::parseToCents('$'.$rest);
        } elseif (preg_match('/^(.*\S)\s+([\d.,]+)$/', $value, $m)) {
            $label = trim($m[1]);
            $cost = Money::parseToCents($m[2]);
        }

        $this->pushPart($label, $cost, $lead);
    }

    /** Normalize a part label and append it (with its cost) to the parts list. */
    private function pushPart(string $label, ?int $cost, ParsedLead $lead): void
    {
        $label = $this->collapseSpaces($label);

        $lead->parts[] = [
            'label' => $label === '' ? null : $label,
            'cost_cents' => $cost,
        ];
    }

    /**
     * Derive the legacy single-part fields from the parts list: the primary
     * part's label, and the summed cost of every part (so profit math counts
     * mouldings/clips too). Cost is null only when no part carried a price.
     */
    private function finalizeParts(ParsedLead $lead): void
    {
        if ($lead->parts === []) {
            return;
        }

        $lead->part_number = $lead->parts[0]['label'] ?? null;

        $costs = array_filter(
            array_column($lead->parts, 'cost_cents'),
            fn ($c) => $c !== null,
        );
        $lead->part_cost_cents = $costs === [] ? null : array_sum($costs);
    }

    private function parseBoolish(string $value): ?bool
    {
        $v = strtolower(trim($value));
        if ($v === '') {
            return null;
        }

        if (in_array($v, ['yes', 'y', 'true', 'available', 'in stock', 'instock', 'have', 'on hand'], true)) {
            return true;
        }

        if (in_array($v, ['no', 'n', 'false', 'out', 'out of stock', 'order', 'need to order', 'backorder'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Fill the city from the parsed service address when the rep didn't send an
     * explicit "City:" line, so the tech-availability text always has a city.
     */
    private function deriveCity(ParsedLead $lead): void
    {
        if ($lead->city !== null || $lead->service_address === null) {
            return;
        }

        $lead->city = $this->cityFromAddress($lead->service_address);
    }

    /**
     * Best-effort city extraction from a US street address. The city is the tail
     * of everything before the ", ST" state token — the last comma-segment
     * ("…, Playa Del Rey, CA 90293" → "Playa Del Rey"), or, when the street and
     * city aren't comma-separated, the words trailing the street suffix
     * ("17043 Gresham St Northridge, CA 91325" → "Northridge"). Returns null when
     * nothing recognizable is found.
     */
    private function cityFromAddress(string $address): ?string
    {
        $work = $this->collapseSpaces($address);
        // Drop a trailing country so the state token is the last landmark.
        $work = trim((string) preg_replace('/,?\s*USA\.?$/i', '', $work));

        // Everything up to the ", ST" state token holds street + city.
        if (preg_match('/^(.*?),\s*[A-Za-z]{2}\b/s', $work, $m)) {
            $left = trim($m[1]);
        } else {
            // No state token — just strip a trailing ZIP.
            $left = trim((string) preg_replace('/\s+\d{5}(?:-\d{4})?$/', '', $work));
        }

        if ($left === '') {
            return null;
        }

        // Comma-separated street/city: the city is the last segment.
        if (str_contains($left, ',')) {
            $segments = explode(',', $left);

            return $this->nullIfEmpty(end($segments));
        }

        // Otherwise the city trails the street suffix ("… St Northridge").
        $suffixes = implode('|', array_map(
            fn ($s) => preg_quote($s, '/'),
            self::STREET_SUFFIXES,
        ));
        if (preg_match('/\b(?:'.$suffixes.')\b\.?\s+(.+)$/i', $left, $m)) {
            return $this->nullIfEmpty($this->collapseSpaces($m[1]));
        }

        return null;
    }

    private function parseEntity(string $value): ?string
    {
        $v = strtolower(trim($value));
        if ($v === '') {
            return null;
        }

        // Normalize punctuation/slashes to single spaces, then match on the
        // distinguishing keyword anywhere in the value. This tolerates the
        // long forms reps actually send ("SFV Auto Glass", "Auto Glass of LA",
        // "Del Mar Auto Glass") instead of only the bare codes. SFV/DelMar are
        // checked before LA so "la" can't false-match a longer name.
        $n = trim(preg_replace('/[^a-z0-9]+/', ' ', $v));

        return match (true) {
            preg_match('/\bsfv\b/', $n) === 1, str_contains($n, 'san fernando') => 'SFV',
            str_contains($n, 'delmar'),
            str_contains($n, 'del mar'),
            str_contains($n, 'san diego') => 'DelMar',
            preg_match('/\bla\b/', $n) === 1, str_contains($n, 'los angeles') => 'LA',
            default => trim($value), // keep raw; resolution validated against entities table at confirm
        };
    }

    /**
     * Capture the raw "Req. Day/Time" value and split it into a day and a time.
     * The day is Today / Tomorrow / a weekday / a specific date; the time is a
     * specific clock time or "As soon as possible". (The dispatch-only custom
     * override isn't parsed — it's filled by hand in the intake form.)
     */
    private function applyRequestedDaytime(string $value, ParsedLead $lead): void
    {
        $raw = $this->nullIfEmpty($value);
        $lead->requested_daytime_raw = $raw;
        if ($raw === null) {
            return;
        }

        [$day, $time] = $this->splitRequestedDaytime($raw);
        $lead->requested_day = $day;
        $lead->requested_date = $this->resolveRequestedDate($day);
        $lead->requested_time = $time;
        // "morning" / "2pm" / "asap" / "after 10am" -> the structured preference
        // the intake Select shows and the scheduling ask branches on. Null when
        // the text says nothing about time, leaving the Select on "No preference".
        $preference = TimePreference::normalize($time);
        $lead->requested_time_preference = $preference?->value;
        // "After" also carries an hour of its own, on the same half-hour grid
        // the intake Select offers.
        $lead->requested_after_time = $preference === TimePreference::After
            ? TimePreference::afterTimeFrom($time)
            : null;
    }

    /**
     * A customer availability constraint the rep wrote in the NOTES instead of
     * on a "Req. Day/Time" line — "Cust only available after 2:30pm". Without
     * this the intake Select stays on "No preference" and the dispatcher
     * re-types the hour by hand, which is how a lead meaning "any time from
     * 2:30 on" was recorded as an EXACT 2:30pm and the AI scheduler then
     * negotiated a 2:30pm arrival (job SFV-2026-0037).
     *
     * Only ever fills a GAP: an explicit requested time on the lead always
     * wins, and the operator still sees and can change the Select. A warning
     * is raised precisely because this was read out of prose.
     *
     * Deliberately ONLY the "after <clock time>" shape. A day part in a note
     * is not safe to claim — "part available in the morning" is about the
     * glass, not about when the customer can be there.
     */
    private function applyNoteTimeConstraint(ParsedLead $lead): void
    {
        if ($lead->requested_time_preference !== null || $lead->notes === null) {
            return;
        }

        // Anchored on the word plus a clock time, so "call 30 mins before" and
        // "after we pick up the part" can never produce an hour.
        $found = preg_match(
            '/\b(?:after|despues|después)(?:\s+de\s+las)?\s+(\d{1,2}(?::\d{2})?\s*(?:a\.?m\.?|p\.?m\.?)?)/i',
            $lead->notes,
            $m
        );
        if ($found !== 1) {
            return;
        }

        // A number that isn't a time of day ("after 30 mins") reads as null —
        // leave the preference unset rather than invent an hour.
        $hour = TimePreference::afterTimeFrom($m[1]);
        if ($hour === null) {
            return;
        }

        $lead->requested_time = 'After '.$this->collapseSpaces($m[1]);
        $lead->requested_time_preference = TimePreference::After->value;
        $lead->requested_after_time = $hour;
        $lead->addWarning(
            'Requested time read from the notes: after '.TimePreference::formatAfterTime($hour)
            .'. Check it before you confirm.'
        );
    }

    /**
     * Turn the day text into a real calendar date (Y-m-d, Pacific) so intake can
     * show it on a date picker: "today"/"tomorrow", a weekday name (the NEXT
     * such day, today counting as a match), or a written date ("7/21", "July 21",
     * "2026-07-21"). A written date with no year lands on the next occurrence, so
     * a January lead saying "Dec 3" doesn't book eleven months back. Returns null
     * when the text isn't a date at all ("next week", "whenever") — the picker
     * then stays empty for the operator to fill.
     */
    private function resolveRequestedDate(?string $day): ?string
    {
        $value = strtolower(trim((string) $day));
        if ($value === '') {
            return null;
        }

        $today = CarbonImmutable::today(config('glass.timezone'));

        if ($value === 'today') {
            return $today->toDateString();
        }
        if ($value === 'tomorrow') {
            return $today->addDay()->toDateString();
        }

        // Weekday name ("thursday", "thurs", "fri") -> the next one, today included.
        $weekdays = [
            'sunday' => 0, 'sun' => 0, 'monday' => 1, 'mon' => 1, 'tuesday' => 2, 'tue' => 2, 'tues' => 2,
            'wednesday' => 3, 'wed' => 3, 'thursday' => 4, 'thu' => 4, 'thur' => 4, 'thurs' => 4,
            'friday' => 5, 'fri' => 5, 'saturday' => 6, 'sat' => 6,
        ];
        $bare = trim(preg_replace('/\b(?:this|next|coming)\b/', ' ', $value));
        $bare = $this->collapseSpaces(trim($bare, " \t,.;:"));
        if (isset($weekdays[$bare])) {
            $delta = ($weekdays[$bare] - (int) $today->dayOfWeek + 7) % 7;

            return $today->addDays($delta)->toDateString();
        }

        // A written date. Carbon parses a lot of junk into "today", so only try
        // when the text actually looks like a date (digits, or a month name).
        $looksLikeDate = preg_match('/\d/', $value) === 1
            || preg_match('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i', $value) === 1;
        if (! $looksLikeDate) {
            return null;
        }

        // "08-01-2026" (dashes, US month-day-year). PHP treats a dash-separated
        // date as day-month-year (European) unless told otherwise, while the same
        // shape with slashes ("08/01/2026") is already read month-day-year -- so
        // this is the one shape Carbon::parse() below would silently misread.
        // This is America; a dash-separated date means the same as a slash one.
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $value, $m) === 1) {
            try {
                $parsed = CarbonImmutable::createFromFormat(
                    'n-j-Y',
                    "{$m[1]}-{$m[2]}-{$m[3]}",
                    config('glass.timezone')
                )->startOfDay();
            } catch (\Throwable) {
                return null;
            }

            return $parsed->toDateString();
        }

        try {
            $parsed = CarbonImmutable::parse($value, config('glass.timezone'))->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        // No explicit year in the text -> roll forward if it already passed.
        if (preg_match('/\b\d{4}\b/', $value) !== 1 && $parsed->lt($today)) {
            $parsed = $parsed->addYear();
        }

        return $parsed->toDateString();
    }

    /**
     * Pull a time out of the requested-daytime text, then treat whatever remains
     * as the day. Returns [day, time], either of which may be null.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitRequestedDaytime(string $raw): array
    {
        $remaining = $this->collapseSpaces($raw);
        $time = null;

        // 1) "As soon as possible" family (asap / earliest / earliest poss).
        if (preg_match('/\b(?:a\.?s\.?a\.?p\.?|as soon as possible|earliest(?:\s+(?:poss\.?|possible))?)\b/i', $remaining, $m, PREG_OFFSET_CAPTURE)) {
            $time = 'As soon as possible';
            $remaining = trim(substr_replace($remaining, '', $m[0][1], strlen($m[0][0])));
        } elseif (preg_match('/\b(?:after|despues|después)\s+(\d{1,2}(?::\d{2})?\s*(?:a\.?m\.?|p\.?m\.?)?)/i', $remaining, $m, PREG_OFFSET_CAPTURE)) {
            // 2) A lower bound: "after 10am", "anytime after 3". Kept WITH its
            //    "after" so it doesn't read as an exact time — normalizeRequestedDay
            //    strips the bare word, which would leave a plain "10am" behind.
            $time = 'After '.$this->collapseSpaces($m[1][0]);
            $remaining = trim(substr_replace($remaining, '', $m[0][1], strlen($m[0][0])));
        } elseif (preg_match('/\b\d{1,2}(?::\d{2})?\s*(?:a\.?m\.?|p\.?m\.?)\b/i', $remaining, $m, PREG_OFFSET_CAPTURE)) {
            // 3) A specific clock time: "2pm", "10 am", "2:30 pm".
            $time = $this->collapseSpaces($m[0][0]);
            $remaining = trim(substr_replace($remaining, '', $m[0][1], strlen($m[0][0])));
        } elseif (preg_match('/\b(morning|afternoon|evening|night)\b/i', $remaining, $m, PREG_OFFSET_CAPTURE)) {
            // 4) A part of the day: "Thursday morning". Pulled out of the day
            //    text so it doesn't corrupt the date resolution, and so the
            //    intake time-preference Select can pre-select it.
            $time = ucfirst(strtolower($m[0][0]));
            $remaining = trim(substr_replace($remaining, '', $m[0][1], strlen($m[0][0])));
        }

        return [$this->normalizeRequestedDay($remaining), $time];
    }

    /** Clean filler/punctuation left after the time is removed; normalize today/tomorrow. */
    private function normalizeRequestedDay(string $value): ?string
    {
        // Drop connective filler words ("at 2pm", "on Thursday", "by tomorrow").
        $value = preg_replace('/\b(?:at|by|on|around|after|before|asap)\b/i', ' ', $value);
        $value = $this->collapseSpaces(trim($value, " \t,.;:-–—"));
        if ($value === '') {
            return null;
        }

        $lower = strtolower($value);
        if ($lower === 'today') {
            return 'Today';
        }
        if (in_array($lower, ['tomorrow', 'tmrw', 'tmw', 'tom'], true)) {
            return 'Tomorrow';
        }

        return $value;
    }

    private function normalizeGlassPosition(string $value): ?string
    {
        $v = strtolower(trim($value));
        if ($v === '') {
            return null;
        }

        // Windshield and back glass have no left/right/front/rear qualifier.
        // "WS" / "BG" are the bare job-type codes reps send ("Job type: WS").
        if (str_contains($v, 'w/s') || str_contains($v, 'windshield') || str_contains($v, 'windscreen')
            || preg_match('/\bws\b/', $v)) {
            return 'Windshield';
        }
        if (str_contains($v, 'back') || str_contains($v, 'backlite') || str_contains($v, 'backlight')
            || preg_match('/\bbg\b/', $v)) {
            return 'Back Glass';
        }

        // Directional qualifier — front/rear axis and left/right side. Accepts
        // spelled words ("rear right"), driver/passenger, and the rep shorthand
        // codes "R/R", "F/L", "RR", "FL" (first letter = front/rear, second =
        // left/right, so "R/R" = Rear Right). Spelled words win over a code.
        $axis = null;   // Front | Rear
        $side = null;   // Left | Right

        if (preg_match('#\b([fr])\s*/?\s*([lr])\b#', $v, $m)) {
            $axis = $m[1] === 'f' ? 'Front' : 'Rear';
            $side = $m[2] === 'l' ? 'Left' : 'Right';
        }
        if (str_contains($v, 'front')) {
            $axis = 'Front';
        } elseif (str_contains($v, 'rear')) {
            $axis = 'Rear';
        }
        if (str_contains($v, 'left') || str_contains($v, 'driver')) {
            $side = 'Left';
        } elseif (str_contains($v, 'right') || str_contains($v, 'passenger')) {
            $side = 'Right';
        }

        $type = match (true) {
            str_contains($v, 'door') => 'Door',
            str_contains($v, 'quarter') => 'Quarter Glass',
            str_contains($v, 'vent') => 'Vent Glass',
            str_contains($v, 'sunroof') => 'Sunroof',
            default => null,
        };

        $parts = array_filter([$axis, $side, $type]);
        if ($parts !== []) {
            return implode(' ', $parts);
        }

        // Nothing recognized — keep the original text for the operator to review.
        return trim($value);
    }

    private function finalizeWarnings(ParsedLead $lead): void
    {
        if ($lead->phone_raw !== null && $lead->phone_e164 === null) {
            $lead->addWarning("Could not normalize phone to E.164: \"{$lead->phone_raw}\".");
        }
        if ($lead->phone_raw === null) {
            $lead->addWarning('No phone number found — required to create a customer.');
        }
        if ($lead->vehicle_raw === null) {
            $lead->addWarning('No vehicle line found.');
        } elseif ($lead->vin === null) {
            $lead->addWarning('No 17-character VIN detected in the vehicle line.');
        }
        if ($lead->quote_amount_cents === null) {
            $lead->addWarning('No quote amount found.');
        }
        if ($lead->entity_code === null) {
            $lead->addWarning('No entity/location specified — operator must select one.');
        }
        foreach ($lead->unknown_lines as $line) {
            $lead->addWarning("Unrecognized line (not dropped): \"{$line}\".");
        }
    }

    private function collapseSpaces(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
