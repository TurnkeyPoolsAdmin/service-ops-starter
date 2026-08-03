<?php

use App\Enums\ParseStatus;
use App\Services\Parsing\WhatsAppLeadParser;

beforeEach(function () {
    $this->parser = new WhatsAppLeadParser;
});

// ---- Fixture A: clean, no time ----

$fixtureA = <<<'TXT'
City: Los Angeles
Name: Alan Fairview
Phone: 818-555-8014
Vehicle: 2022 Tesla Model Y - 7SAYGDEF3NF000287 W/S
Part#: DW2853GTY $350.00
Part Available:
Quote: 950
Req. Day/Time: Thursday
Text/Call Pref:
Entity: LA
TXT;

it('parses fixture A (clean, no time)', function () use ($fixtureA) {
    $lead = $this->parser->parse($fixtureA);

    expect($lead->city)->toBe('Los Angeles')
        ->and($lead->first_name)->toBe('Alan')
        ->and($lead->last_name)->toBe('Fairview')
        ->and($lead->phone_e164)->toBe('+18185558014')
        ->and($lead->vehicle_year)->toBe('2022')
        ->and($lead->vehicle_make)->toBe('Tesla')
        ->and($lead->vehicle_model)->toBe('Model Y')
        ->and($lead->vin)->toBe('7SAYGDEF3NF000287')
        ->and($lead->glass_position)->toBe('Windshield')
        ->and($lead->part_number)->toBe('DW2853GTY')
        ->and($lead->part_cost_cents)->toBe(35000)
        ->and($lead->quote_amount_cents)->toBe(95000)
        ->and($lead->requested_daytime_raw)->toBe('Thursday')
        ->and($lead->requested_day)->toBe('Thursday')
        ->and($lead->requested_time)->toBeNull()
        ->and($lead->entity_code)->toBe('LA');

    // No appointment time should ever be inferred from "Thursday".
    expect($lead->status())->toBe(ParseStatus::Clean);
    expect($lead->warnings)->toBeEmpty();
});

// ---- Fixture B: explicit date + time ----

$fixtureB = <<<'TXT'
City: San Diego
Name: Maria Gomez
Phone: (858) 555-0199
Vehicle: 2019 Honda CR-V 5J6RW2H89KL000111 W/S
Part #: FW04123 $410.00
Quote: 720
Req. Day/Time: Thursday 2pm
Text/Call Pref: Text
Entity: DelMar
TXT;

it('parses fixture B (explicit date+time)', function () use ($fixtureB) {
    $lead = $this->parser->parse($fixtureB);

    expect($lead->first_name)->toBe('Maria')
        ->and($lead->last_name)->toBe('Gomez')
        ->and($lead->phone_e164)->toBe('+18585550199')
        ->and($lead->vehicle_year)->toBe('2019')
        ->and($lead->vehicle_make)->toBe('Honda')
        ->and($lead->vehicle_model)->toBe('CR-V')
        ->and($lead->vin)->toBe('5J6RW2H89KL000111')
        ->and($lead->glass_position)->toBe('Windshield')
        ->and($lead->part_number)->toBe('FW04123')
        ->and($lead->part_cost_cents)->toBe(41000)
        ->and($lead->quote_amount_cents)->toBe(72000)
        ->and($lead->entity_code)->toBe('DelMar');

    // "Thursday 2pm" is captured raw and split into day + time; scheduled_at is
    // NEVER set by the parser.
    expect($lead->requested_daytime_raw)->toBe('Thursday 2pm');
    expect($lead->requested_day)->toBe('Thursday');
    expect($lead->requested_time)->toBe('2pm');
    expect($lead->status())->toBe(ParseStatus::Clean);
});

it('splits requested day/time into day and time parts', function () {
    $parser = new WhatsAppLeadParser;

    $cases = [
        // raw                       => [day, time]
        'Thursday' => ['Thursday', null],
        'Thursday 2pm' => ['Thursday', '2pm'],
        'Tomorrow at 10 am' => ['Tomorrow', '10 am'],
        'today , earliest poss' => ['Today', 'As soon as possible'],
        'ASAP' => [null, 'As soon as possible'],
        'tmrw 2:30 pm' => ['Tomorrow', '2:30 pm'],
        'Tomorrow 6/25' => ['Tomorrow 6/25', null],
        'as soon as possible' => [null, 'As soon as possible'],
        'today' => ['Today', null],
    ];

    foreach ($cases as $raw => [$day, $time]) {
        $lead = $parser->parse("Phone: 8185551212\nReq. Day/Time: {$raw}\nEntity: LA");
        expect($lead->requested_day)->toBe($day, "day for: {$raw}")
            ->and($lead->requested_time)->toBe($time, "time for: {$raw}");
    }
});

it('resolves the requested day to a real date for the intake calendar', function () {
    // A Monday, so "Thursday" is later the same week.
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-07-20 09:00', 'America/Los_Angeles'));

    $cases = [
        'today' => '2026-07-20',
        'Tomorrow at 10 am' => '2026-07-21',
        'Thursday 2pm' => '2026-07-23',
        'Monday' => '2026-07-20',   // today counts as the next Monday
        'Sunday' => '2026-07-26',
        '7/24' => '2026-07-24',
        'July 24' => '2026-07-24',
        '2026-08-03' => '2026-08-03',
        '08-01-2026' => '2026-08-01', // dashes, US month-day-year -- not Jan 8th
        '7/1' => '2027-07-01',      // already passed this year -> next year
        'ASAP' => null,             // no day at all
        'whenever works' => null,   // not a date
    ];

    foreach ($cases as $raw => $expected) {
        $lead = $this->parser->parse("Phone: 8185551212\nReq. Day/Time: {$raw}\nEntity: LA");
        expect($lead->requested_date)->toBe($expected, "date for: {$raw}");
    }

    Carbon\Carbon::setTestNow();
});

// ---- Fixture C: messy, reordered, missing entity, label variants ----

$fixtureC = <<<'TXT'
Name:   Bob   O'Brien
Car: Chevy Silverado 1500 2018  1GCUKREC0JF000222   back glass
Phone: 818.555.7777
Quote: 600
Part Number: DB1234 $300
Requested Time:
Contact Pref: call
TXT;

it('parses fixture C (messy, missing entity)', function () use ($fixtureC) {
    $lead = $this->parser->parse($fixtureC);

    expect($lead->first_name)->toBe('Bob')
        ->and($lead->last_name)->toBe("O'Brien")
        ->and($lead->phone_e164)->toBe('+18185557777')
        ->and($lead->vin)->toBe('1GCUKREC0JF000222')
        ->and($lead->vehicle_year)->toBe('2018')
        ->and($lead->vehicle_make)->toBe('Chevy')
        ->and($lead->vehicle_model)->toBe('Silverado 1500')
        ->and($lead->glass_position)->toBe('Back Glass')
        ->and($lead->part_number)->toBe('DB1234')
        ->and($lead->part_cost_cents)->toBe(30000)
        ->and($lead->quote_amount_cents)->toBe(60000);

    // Missing entity must surface as a warning, not a silent default.
    expect($lead->entity_code)->toBeNull();
    expect($lead->status())->toBe(ParseStatus::Warnings);
    expect($lead->warnings)->toContain('No entity/location specified — operator must select one.');
});

it('never infers scheduled_at and preserves raw text', function () use ($fixtureA) {
    $lead = $this->parser->parse($fixtureA);

    expect($lead->raw)->toBe($fixtureA);
    expect($lead->toPayload())->toHaveKey('requested_daytime_raw', 'Thursday');
});

it('flags an unnormalizable phone as a warning without dropping it', function () {
    $lead = $this->parser->parse("Name: Test User\nPhone: 12-34\nEntity: LA");

    expect($lead->phone_e164)->toBeNull()
        ->and($lead->phone_raw)->toBe('12-34')
        ->and($lead->status())->toBe(ParseStatus::Warnings);
});

it('keeps unknown lines as warnings rather than dropping them', function () {
    $lead = $this->parser->parse("Name: Jane Doe\nPhone: 8185551212\nRandom note about tint\nEntity: SFV");

    expect($lead->unknown_lines)->toContain('Random note about tint');
    expect(collect($lead->warnings)->contains(fn ($w) => str_contains($w, 'Random note about tint')))->toBeTrue();
});

// ---- Fixture D: header entity ("LA Reference") + Entity: line, address, two parts ----

$fixtureD = <<<'TXT'
LA Reference
City:Sylmar
Name: Ron Seaton
Phone: 323-555-3658
Address: 14163 Saddletree Ct, Sylmar, CA 91342, USA
Vehicle: 2020 Honda Pilot - 5FNYF6H56LB000535 W/S
Part#: FW04239 GTY FYG $155.00
USM F4101 GOLD $7.90
Part Available:
Quote: $682.00
Req. Day/Time: Tomorrow at 10 am
Text/Call Pref: Both
Entity: LA
TXT;

it('parses the header entity reference without an unknown-line warning', function () use ($fixtureD) {
    $lead = $this->parser->parse($fixtureD);

    // "LA Reference" + "Entity: LA" both present — resolve once, no conflict noise.
    expect($lead->entity_code)->toBe('LA')
        ->and($lead->unknown_lines)->toBe([])
        ->and($lead->status())->toBe(ParseStatus::Clean);
});

it('captures the service address', function () use ($fixtureD) {
    $lead = $this->parser->parse($fixtureD);

    expect($lead->service_address)->toBe('14163 Saddletree Ct, Sylmar, CA 91342, USA');
});

it('parses a $-prefixed quote', function () use ($fixtureD) {
    $lead = $this->parser->parse($fixtureD);

    expect($lead->quote_amount_cents)->toBe(68200);
});

it('consumes a contact-preference line silently without a warning', function () {
    // We no longer track contact preference — the line is recognized only so it
    // doesn't fall through as an unrecognized-line warning.
    $lead = $this->parser->parse("Text/Call Pref: Call & Text\nPhone: 8185551212\nEntity: LA");

    // The pref line is consumed, not flagged as an unrecognized line.
    expect($lead->unknown_lines)->toBe([])
        ->and(collect($lead->warnings)->contains(fn ($w) => str_contains($w, 'Unrecognized')))->toBeFalse();
});

it('captures multiple parts and sums their cost', function () use ($fixtureD) {
    $lead = $this->parser->parse($fixtureD);

    expect($lead->parts)->toHaveCount(2)
        ->and($lead->parts[0])->toBe(['label' => 'FW04239 GTY FYG', 'cost_cents' => 15500])
        ->and($lead->parts[1])->toBe(['label' => 'USM F4101 GOLD', 'cost_cents' => 790])
        // Legacy fields: primary label + summed cost (155.00 + 7.90).
        ->and($lead->part_number)->toBe('FW04239 GTY FYG')
        ->and($lead->part_cost_cents)->toBe(16290);
});

it('resolves a header entity reference when no Entity: line is present', function () {
    $lead = $this->parser->parse("Del Mar Reference\nName: Pat Lee\nPhone: 8585550000");

    expect($lead->entity_code)->toBe('DelMar');
});

// ---- Fixture E: unlabeled address + two parts mashed on one line ----

$fixtureE = <<<'TXT'
City: Northridge
Name: Susan West
Phone: 818-555-4306
17043 Gresham St Northridge, CA 91325
Vehicle: 2009 Acura TSX - JH4CU26689C000006 W/S
Part#: FW02994 GBN FYG $101.75 WFT F2994 PRP	 $11.72
Part Available:
Quote: $370.00
Req. Day/Time: Tomorrow 6/25
Text/Call Pref: Call
Entity: SFV
TXT;

it('captures an unlabeled street address', function () use ($fixtureE) {
    $lead = $this->parser->parse($fixtureE);

    expect($lead->service_address)->toBe('17043 Gresham St Northridge, CA 91325')
        // The address line must not surface as an unrecognized line.
        ->and($lead->unknown_lines)->toBe([]);
});

it('captures two parts mashed on one line and sums their cost', function () use ($fixtureE) {
    $lead = $this->parser->parse($fixtureE);

    expect($lead->parts)->toHaveCount(2)
        ->and($lead->parts[0])->toBe(['label' => 'FW02994 GBN FYG', 'cost_cents' => 10175])
        ->and($lead->parts[1])->toBe(['label' => 'WFT F2994 PRP', 'cost_cents' => 1172])
        ->and($lead->part_number)->toBe('FW02994 GBN FYG')
        // 101.75 + 11.72
        ->and($lead->part_cost_cents)->toBe(11347);
});

it('parses the rest of an unlabeled-address lead correctly', function () use ($fixtureE) {
    $lead = $this->parser->parse($fixtureE);

    expect($lead->first_name)->toBe('Susan')
        ->and($lead->last_name)->toBe('West')
        ->and($lead->phone_e164)->toBe('+18185554306')
        ->and($lead->vin)->toBe('JH4CU26689C000006')
        ->and($lead->vehicle_year)->toBe('2009')
        ->and($lead->vehicle_make)->toBe('Acura')
        ->and($lead->vehicle_model)->toBe('TSX')
        ->and($lead->glass_position)->toBe('Windshield')
        ->and($lead->quote_amount_cents)->toBe(37000)
        ->and($lead->entity_code)->toBe('SFV');
});

it('does not mistake a labeled vehicle line for an address', function () {
    $lead = $this->parser->parse(
        "Phone: 8185551212\nVehicle: 2009 Acura TSX - JH4CU26689C000006 W/S\nEntity: SFV"
    );

    expect($lead->service_address)->toBeNull();
});

it('prefers a labeled Address over a later unlabeled address line', function () {
    $lead = $this->parser->parse(
        "Address: 1 First St, Sylmar, CA 91342\nPhone: 8185551212\n999 Second Ave Sylmar, CA 91342\nEntity: LA"
    );

    expect($lead->service_address)->toBe('1 First St, Sylmar, CA 91342');
});

// ---- Fixture F: hyphenated multi-word make ("Mercedes-Benz") ----

$fixtureF = <<<'TXT'
City: 19201 Nordhoff St Northridge, CA 91324
Name: Amy Garcia
Phone: 818-555-4057
Vehicle: 2023 Mercedes-Benz C300 AMC - vin W1KAF4GB3PR000919 F/R Door
Part#: F/R - Door - FD29111GTNN $83.20
Part Available: san fer
Quote: 428
Req. Day/Time: today , earliest poss
Text/Call Pref: tx
Entity: SFV
TXT;

it('splits a hyphenated multi-word make (Mercedes-Benz)', function () use ($fixtureF) {
    $lead = $this->parser->parse($fixtureF);

    expect($lead->vehicle_year)->toBe('2023')
        // Hyphen preserved in the make; model is what follows.
        ->and($lead->vehicle_make)->toBe('Mercedes-Benz')
        ->and($lead->vehicle_model)->toBe('C300 AMC')
        ->and($lead->vin)->toBe('W1KAF4GB3PR000919')
        ->and($lead->glass_position)->toBe('Front Right Door')
        ->and($lead->vehicle_raw)->toBe('2023 Mercedes-Benz C300 AMC - vin W1KAF4GB3PR000919 F/R Door');

    // The make/model split must NOT raise a "couldn't split" warning.
    expect(collect($lead->warnings)->contains(fn ($w) => str_contains($w, 'split vehicle make/model')))
        ->toBeFalse();
});

// ---- Standalone VIN line ----

it('captures a VIN sent on its own dash-prefixed line, separate from the vehicle', function () {
    $lead = $this->parser->parse(<<<'TXT'
    City: Burbank
    Name: Stella Marden
    Phone: 323-555-0060
    217 E Alameda Ave, Burbank, CA 91502
    Vehicle: 2023 Toyota Rav-4 Hybrid Back
    VIN- 4T3T6RFV0PU000124
    Part#: FB28059 YPY FYG $149.60
    Quote: 481.8
    Entity: LA
    TXT);

    expect($lead->vin)->toBe('4T3T6RFV0PU000124')
        ->and($lead->vehicle_raw)->toBe('2023 Toyota Rav-4 Hybrid Back');

    // The VIN line must NOT fall through as unknown, and no "no VIN" warning.
    expect(collect($lead->warnings)->contains(fn ($w) => str_contains($w, 'Unrecognized line')))
        ->toBeFalse();
    expect(collect($lead->warnings)->contains(fn ($w) => str_contains($w, 'VIN')))
        ->toBeFalse();
});

it('captures a labeled "VIN:" line sent separately from the vehicle', function () {
    $lead = $this->parser->parse(<<<'TXT'
    Name: Jane Doe
    Phone: 818-555-8014
    Vehicle: 2022 Tesla Model Y W/S
    VIN: 7SAYGDEF3NF000287
    Quote: 950
    Entity: SFV
    TXT);

    expect($lead->vin)->toBe('7SAYGDEF3NF000287');
    expect(collect($lead->warnings)->contains(fn ($w) => str_contains($w, 'Unrecognized line')))
        ->toBeFalse();
});

// ---- Notes ----

it('captures a labeled Notes line without raising an unknown-line warning', function () {
    $lead = $this->parser->parse(<<<'TXT'
    Name: Michael Zamora
    Phone: 615-555-5572
    Vehicle: 2014 Ford Fusion Energi - 3FA6P0PU2ER000742 W/S
    Quote: $699.00
    Entity: LA
    Notes: Free wipers to be given to the customer. Vehicle uses the same wiper in both side, so we need 2 26" wiper
    TXT);

    expect($lead->notes)->toBe('Free wipers to be given to the customer. Vehicle uses the same wiper in both side, so we need 2 26" wiper');

    // The notes line must NOT fall through to an "Unrecognized line" warning.
    expect(collect($lead->warnings)->contains(fn ($w) => str_contains($w, 'Unrecognized line')))
        ->toBeFalse();
});

it('captures unlabeled continuation lines under a Notes block', function () {
    $lead = $this->parser->parse(<<<'TXT'
    Name: Jane Doe
    Phone: 818-555-8014
    Vehicle: 2022 Tesla Model Y - 7SAYGDEF3NF000287 W/S
    Quote: 950
    Entity: SFV
    Notes: Gate code 4421.
    Park in the alley behind the house.
    Call on arrival.
    TXT);

    expect($lead->notes)->toBe("Gate code 4421.\nPark in the alley behind the house.\nCall on arrival.");
    expect(collect($lead->warnings)->contains(fn ($w) => str_contains($w, 'Unrecognized line')))
        ->toBeFalse();
});

// ---- Entity: long company forms resolve to the code ----

it('resolves an entity from its long company form', function () {
    $cases = [
        'SFV AUTO GLASS' => 'SFV',
        'SFV Auto Glass' => 'SFV',
        'San Fernando Valley Auto Glass' => 'SFV',
        'Auto Glass of LA' => 'LA',
        'Del Mar Auto Glass' => 'DelMar',
        'LA' => 'LA',
        'delmar' => 'DelMar',
    ];

    foreach ($cases as $raw => $code) {
        $lead = $this->parser->parse("Phone: 8185551212\nEntity: {$raw}");
        expect($lead->entity_code)->toBe($code, "entity for: {$raw}");
    }
});

// ---- Name: one-letter middle initials are dropped from the last name ----

it('drops a one-letter middle initial from the last name', function () {
    $cases = [
        'Christine E Bezdeny' => ['Christine', 'Bezdeny'],
        'Christine E. Bezdeny' => ['Christine', 'Bezdeny'],
        'John A B Smith' => ['John', 'Smith'],
        // No middle initial — unchanged.
        'Maria Gomez' => ['Maria', 'Gomez'],
        // Compound surname with no initial — kept intact.
        'Ana Van Der Berg' => ['Ana', 'Van Der Berg'],
    ];

    foreach ($cases as $raw => [$first, $last]) {
        $lead = $this->parser->parse("Name: {$raw}\nPhone: 8185551212\nEntity: LA");
        expect($lead->first_name)->toBe($first, "first for: {$raw}")
            ->and($lead->last_name)->toBe($last, "last for: {$raw}");
    }
});

// ---- Name: an ALL-CAPS name is normalized to title case ----

it('normalizes an all-caps name to title case', function () {
    $cases = [
        'MELISSA GOMEZ' => ['Melissa', 'Gomez'],
        "PATRICK O'BRIEN" => ['Patrick', "O'Brien"],
        'MARY-JANE WATSON' => ['Mary-Jane', 'Watson'],
        // Middle initial still dropped from the last name.
        'CHRISTINE E BEZDENY' => ['Christine', 'Bezdeny'],
        // Mixed case is left exactly as typed.
        'Sam McDonald' => ['Sam', 'McDonald'],
        'ANA de la Cruz' => ['ANA', 'de la Cruz'],
    ];

    foreach ($cases as $raw => [$first, $last]) {
        $lead = $this->parser->parse("Name: {$raw}\nPhone: 8185551212\nEntity: LA");
        expect($lead->first_name)->toBe($first, "first for: {$raw}")
            ->and($lead->last_name)->toBe($last, "last for: {$raw}");
    }
});

// ---- Glass position: directional codes expand; extracted even without a VIN ----

it('expands directional glass-position codes to readable text', function () {
    $cases = [
        'R/R Door' => 'Rear Right Door',
        'F/L Door' => 'Front Left Door',
        'RR Door' => 'Rear Right Door',
        'rear right door' => 'Rear Right Door',
        'driver door' => 'Left Door',
        'W/S' => 'Windshield',
        'back glass' => 'Back Glass',
        'F/L Quarter' => 'Front Left Quarter Glass',
    ];

    foreach ($cases as $raw => $expected) {
        // Drive it through a no-VIN vehicle line so extraction runs too.
        $lead = $this->parser->parse("Phone: 8185551212\nVehicle: 2023 Subaru Crosstrek {$raw}\nEntity: SFV");
        expect($lead->glass_position)->toBe($expected, "position for: {$raw}");
    }
});

// ---- Fixture H: the "Cust location / Job type / Part availability" rep template ----

$fixtureH = <<<'TXT'
Name:  Brandon Rudd
Cust location: 220 N Toluca StLos Angeles, CA 90026, USA
Phone:  256-555-5712
Vehicle: 2009 Chevrolet Malibu 1G1ZH57B29F000055
Job type: WS
Part availability : DW01746 GTY FYG	 $75.00
Part price + Quote provided :  347 MG SF
Request for a time window: 07/06/2026
Entity: LA
TXT;

it('parses the Cust location rep template end-to-end', function () use ($fixtureH) {
    $lead = $this->parser->parse($fixtureH);

    expect($lead->first_name)->toBe('Brandon')
        ->and($lead->last_name)->toBe('Rudd')
        // Google-Maps paste jams the suffix against the city — space restored.
        ->and($lead->service_address)->toBe('220 N Toluca St Los Angeles, CA 90026, USA')
        ->and($lead->phone_e164)->toBe('+12565555712')
        ->and($lead->vin)->toBe('1G1ZH57B29F000055')
        ->and($lead->vehicle_make)->toBe('Chevrolet')
        ->and($lead->vehicle_model)->toBe('Malibu')
        // "Job type: WS" — bare code, and it must survive the VIN-anchored
        // vehicle line that carries no trailing position of its own.
        ->and($lead->glass_position)->toBe('Windshield')
        // The part+price rode under "Part availability:", not "Part#:".
        ->and($lead->parts)->toBe([['label' => 'DW01746 GTY FYG', 'cost_cents' => 7500]])
        ->and($lead->quote_amount_cents)->toBe(34700)
        ->and($lead->requested_daytime_raw)->toBe('07/06/2026')
        ->and($lead->entity_code)->toBe('LA');

    expect($lead->unknown_lines)->toBe([]);
    expect($lead->status())->toBe(ParseStatus::Clean);
});

it('parses the same template with BG job type and extra note labels', function () {
    $lead = $this->parser->parse(<<<'TXT'
    Name: Marvin C
    Cust location:  11301 Wilshire BlvdLos Angeles, CA 90073, USA
    Phone: 323-555-7690
    Vehicle: 1996 ford mustang  1falp45x4tf000715
    Job type: BG
    Part availability : DB08845 GTN 185.66
    Part price + Quote provided : 764.50
    Request for a time window: 07-06-2026
    Entity: LA

    Note: Please, reachout before heading to his work address.

    Addional note: Cust might go with the CAR brand on Monday need to confirm Quote: 985 OEM and for OEE 764.50
    TXT);

    expect($lead->service_address)->toBe('11301 Wilshire Blvd Los Angeles, CA 90073, USA')
        ->and($lead->vin)->toBe('1FALP45X4TF000715')
        ->and($lead->glass_position)->toBe('Back Glass')
        // Part with a trailing bare price (no $).
        ->and($lead->parts)->toBe([['label' => 'DB08845 GTN', 'cost_cents' => 18566]])
        ->and($lead->quote_amount_cents)->toBe(76450)
        // "Note:" + the (misspelled) "Addional note:" both fold into notes.
        ->and($lead->notes)->toBe("Please, reachout before heading to his work address.\nCust might go with the CAR brand on Monday need to confirm Quote: 985 OEM and for OEE 764.50");

    expect($lead->unknown_lines)->toBe([]);
});

it('discards a boolish Part Availability value without creating a part', function () {
    // Availability is no longer tracked; a bare yes/no is dropped, not parsed
    // as a part, and doesn't surface as an unrecognized line.
    $lead = $this->parser->parse("Phone: 8185551212\nPart Availability: yes\nEntity: LA");

    expect($lead->parts)->toBe([])
        ->and($lead->unknown_lines)->toBe([]);
});

it('still captures a part number sent under a Part Availability label', function () {
    // Some reps put the part+price under "Part availability:" instead of "Part#:".
    $lead = $this->parser->parse("Phone: 8185551212\nPart Availability: FW04239 GTY \$155.00\nEntity: LA");

    expect($lead->parts)->toBe([['label' => 'FW04239 GTY', 'cost_cents' => 15500]]);
});

it('derives the city from the service address when no City line is sent', function () {
    // No "City:" label — the city must be pulled from the address for the
    // tech-availability text. Covers the two shapes reps send:
    $cases = [
        // Comma-separated street/city.
        ['7600 W Manchester Ave, Playa Del Rey, CA 90293', 'Playa Del Rey'],
        // City jammed onto the street suffix (Google-Maps paste), no comma.
        ['17043 Gresham St Northridge, CA 91325', 'Northridge'],
        ['220 N Toluca St Los Angeles, CA 90026', 'Los Angeles'],
        // Trailing country.
        ['11301 Wilshire Blvd Los Angeles, CA 90073, USA', 'Los Angeles'],
    ];

    foreach ($cases as [$address, $city]) {
        $lead = $this->parser->parse("Phone: 8185551212\nAddress: {$address}\nEntity: LA");
        expect($lead->city)->toBe($city);
    }
});

it('keeps an explicit City line over the address-derived city', function () {
    $lead = $this->parser->parse(
        "City: Sylmar\nPhone: 8185551212\nAddress: 14163 Saddletree Ct, Somewhere, CA 91342\nEntity: LA"
    );

    expect($lead->city)->toBe('Sylmar');
});

it('falls back to address detection for an unknown label with an address-shaped value', function () {
    $lead = $this->parser->parse(
        "Phone: 8185551212\nDrop off spot: 999 Second Ave Sylmar, CA 91342\nEntity: LA"
    );

    expect($lead->service_address)->toBe('999 Second Ave Sylmar, CA 91342')
        ->and($lead->unknown_lines)->toBe([]);
});

// ---- Fixture G: the reported real lead (no VIN, long entity, middle initial) ----

$fixtureG = <<<'TXT'
City: Playa Del Rey
Name: Christine E Bezdeny
Phone: 818-555-6976
7600 W Manchester Ave, Playa Del Rey, CA 90293
Vehicle: 2023 Subaru Crosstrek limited  R/R Door
Part#: FD27694 YPY FYG  $49.07
Quote: 371.8
Req. Day/Time: 6/29/26 9am
Text/Call Pref: please reach out to the cx
Entity: SFV AUTO GLASS
TXT;

it('parses the reported lead end-to-end', function () use ($fixtureG) {
    $lead = $this->parser->parse($fixtureG);

    expect($lead->city)->toBe('Playa Del Rey')
        ->and($lead->first_name)->toBe('Christine')
        ->and($lead->last_name)->toBe('Bezdeny')
        ->and($lead->phone_e164)->toBe('+18185556976')
        ->and($lead->service_address)->toBe('7600 W Manchester Ave, Playa Del Rey, CA 90293')
        ->and($lead->vehicle_year)->toBe('2023')
        ->and($lead->vehicle_make)->toBe('Subaru')
        ->and($lead->vehicle_model)->toBe('Crosstrek limited')
        ->and($lead->glass_position)->toBe('Rear Right Door')
        ->and($lead->part_number)->toBe('FD27694 YPY FYG')
        ->and($lead->part_cost_cents)->toBe(4907)
        ->and($lead->quote_amount_cents)->toBe(37180)
        ->and($lead->entity_code)->toBe('SFV');
});
