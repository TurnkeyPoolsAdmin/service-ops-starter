<?php

use App\Enums\VendorLineStatus;
use App\Models\Employee;
use App\Models\VendorDocument;
use App\Models\VendorDocumentLine;
use App\Models\VendorImport;
use App\Models\Warehouse;
use App\Services\Vendors\Mygrant\BillingCycle;
use App\Services\Vendors\Mygrant\OrderHistoryImporter;
use App\Services\Vendors\Mygrant\PartDescriptionParser;
use App\Support\PartNumber;
use Carbon\Carbon;

beforeEach(function () {
    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->importer = new OrderHistoryImporter;
    $this->csv = file_get_contents(base_path('tests/Fixtures/mygrant/order_history_sample.csv'));
});

afterEach(fn () => Carbon::setTestNow());

// ---- CSV ingest basics ----
// The fixture is 34 data rows: the original 18 covering the general cases,
// plus the 16 real lines of invoice I05071741-0 (added for the orphan-line
// regression below — see "never lets an orphan line...").

it('imports every data row in the trimmed fixture', function () {
    $import = $this->importer->import($this->csv, $this->actor, 'order_history_sample.csv');

    expect($import)->toBeInstanceOf(VendorImport::class)
        ->and($import->result)->toBe('ok')
        ->and($import->lines_parsed)->toBe(34)
        ->and($import->lines_created)->toBe(34)
        ->and($import->lines_updated)->toBe(0)
        ->and(VendorDocumentLine::count())->toBe(34);
});

it('re-importing the same file updates rather than duplicates', function () {
    $this->importer->import($this->csv, $this->actor, 'first.csv');
    $second = $this->importer->import($this->csv, $this->actor, 'second.csv');

    expect($second->lines_created)->toBe(0)
        ->and($second->lines_updated)->toBe(34)
        ->and(VendorDocumentLine::count())->toBe(34);
});

it('auto-creates a Mygrant warehouse row for a warehouse code not already on file', function () {
    $this->importer->import($this->csv, $this->actor, 'order_history_sample.csv');

    $warehouse = Warehouse::where('supplier', 'mygrant')->where('supplier_code', 'B006')->first();

    expect($warehouse)->not->toBeNull()
        ->and($warehouse->name)->toBe('MGC La Puente CA');
});

// ---- pending_pickup (zero-total) lines ----

it('stores a zero-total line as pending_pickup with no document', function () {
    $this->importer->import($this->csv, $this->actor, 'order_history_sample.csv');

    $line = VendorDocumentLine::where('order_line', 'S71658409-1')->firstOrFail();

    expect($line->status)->toBe(VendorLineStatus::PendingPickup)
        ->and($line->line_total_cents)->toBe(0)
        ->and($line->vendor_document_id)->toBeNull();
});

it('flips a pending_pickup line to billed when a later import shows it picked up', function () {
    $this->importer->import($this->csv, $this->actor, 'order_history_sample.csv');

    $before = VendorDocumentLine::where('order_line', 'S71658409-1')->firstOrFail();
    expect($before->status)->toBe(VendorLineStatus::PendingPickup);

    // The same order line, now actually picked up and invoiced — Quantity_Delivered,
    // Line_Total, Invoice_Number/Date all filled in, exactly what a later export
    // of the same rolling window would show for a part that finally arrived.
    $updatedRow = 'S71658409-0,S71658409-1,12/31/2025 8:50:22 AM,1/6/2026 3:04:01 PM,C000001-000,C000001-001,,AtBranch,B006,MGC La Puente CA,WILL CALL,FD25745 GTY FYG,13-17 Honda Accord 4D Sedan F/L (Slr) **S/A FD25719**,1.00000,1.00000,39.92000,39.92000,0.00000,39.92000,I09999999-0,1/11/2026 12:00:00 AM,';
    $csv = str_replace(
        'S71658409-0,S71658409-1,12/31/2025 8:50:22 AM,1/6/2026 3:04:01 PM,C000001-000,C000001-001,,AtBranch,B006,MGC La Puente CA,WILL CALL,FD25745 GTY FYG,13-17 Honda Accord 4D Sedan F/L (Slr) **S/A FD25719**,1.00000,0.00000,39.92000,0.00000,0.00000,0.00000,,,',
        $updatedRow,
        $this->csv
    );

    $this->importer->import($csv, $this->actor, 'order_history_sample_v2.csv');

    $after = VendorDocumentLine::where('order_line', 'S71658409-1')->firstOrFail();
    expect($after->status)->toBe(VendorLineStatus::Billed)
        ->and($after->line_total_cents)->toBe(3992)
        ->and($after->invoice_number)->toBe('I09999999-0')
        ->and($after->vendor_document_id)->not->toBeNull();

    $document = VendorDocument::find($after->vendor_document_id);
    expect($document->document_number)->toBe('I09999999-0')
        ->and($document->net_amount_cents)->toBe(3992);
});

// ---- invoice documents / month-end cut ----

it('groups the month-end cut invoice with the right net and line count', function () {
    // I05047667-0: 5 lines confirmed 6/29-6/30/2026, cut short at month-end
    // (6/30) rather than the ordinary next-Sunday (7/5) — verified against
    // the real Mygrant invoice PDF: 403.06+188.00+188.00+408.00+74.72 = 1261.78.
    $this->importer->import($this->csv, $this->actor, 'order_history_sample.csv');

    $document = VendorDocument::where('document_number', 'I05047667-0')->firstOrFail();

    expect($document->cut_date)->toBe('2026-06-30')
        ->and($document->line_count)->toBe(5)
        ->and($document->net_amount_cents)->toBe(126178)
        ->and($document->settle_date)->toBe(BillingCycle::settleDate('2026-06-30'));
});

it('an invoice net keeps counting a line that was later returned', function () {
    $this->importer->import($this->csv, $this->actor, 'order_history_sample.csv');
    $document = VendorDocument::where('document_number', 'I05047667-0')->firstOrFail();
    expect($document->net_amount_cents)->toBe(126178);

    // Simulate what CreditMemoImporter does to a returned line, WITHOUT going
    // through it, then re-run the same order-history import — the invoice's
    // own total must stay exactly what was billed; the return nets out on
    // its own separate credit-memo document, never by mutating this one.
    VendorDocumentLine::where('order_line', 'S74284695-2')->update([
        'status' => 'returned',
        'returned_at' => now(),
    ]);

    $this->importer->import($this->csv, $this->actor, 'order_history_sample_v2.csv');

    $document->refresh();
    expect($document->net_amount_cents)->toBe(126178)
        ->and($document->line_count)->toBe(5);
});

it('promotes the open (uninvoiced) document to carry the invoice number once the WHOLE week is invoiced, without duplicating it', function () {
    // Realistic shape: Mygrant invoices an entire cut-date week atomically,
    // so every line sharing a cut date gets the SAME new invoice number in
    // the same later export. S74628731-1, S74641003-1/-2, and
    // S74671852-1/-2 all share one cut date (confirmed 7/20-7/23/2026, all
    // inside the same next-Sunday window) with no invoice yet in the fixture.
    // Frozen well away from any date used elsewhere in the fixture so the
    // fixture's own blank-Confirmed_Date rows (which bucket by "today")
    // can't coincidentally land on the same cut date as this test's target
    // week and change what's being exercised.
    Carbon::setTestNow(Carbon::parse('2026-08-10 09:00', 'America/Los_Angeles'));

    $this->importer->import($this->csv, $this->actor, 'order_history_sample.csv');

    $sharedOrderLines = ['S74628731-1', 'S74641003-1', 'S74641003-2', 'S74671852-1', 'S74671852-2'];
    $lines = VendorDocumentLine::whereIn('order_line', $sharedOrderLines)->get()->keyBy('order_line');
    $cutDate = $lines->first()->cut_date;
    expect($lines->pluck('cut_date')->unique())->toHaveCount(1); // confirms they really share one cut date

    $openDocumentId = $lines->first()->vendor_document_id;
    expect(VendorDocument::findOrFail($openDocumentId)->document_number)->toBeNull();

    // Each row's trailing ",,," (Invoice_Number, Invoice_Date, trailing blank
    // column) becomes the new invoice number/date — every other field is
    // untouched, so a plain string replace of just that tail is unambiguous.
    $rowTails = [
        'S74628731-0,S74628731-1,7/20/2026 1:56:49 PM,7/20/2026 4:42:45 PM,C000001-000,C000001-001,C000001-001 : jose,AtBranch,B014,MGC San Fernando CA,WILL CALL,FW06451 GTY FYG,22-25 Audi Q8 E-Tron 4D U (Slr Adaptive Cruise Ctrl w/Traffic Jam Asst LK,1.00000,1.00000,280.00000,280.00000,0.00000,280.00000,,,',
        'S74641003-0,S74641003-1,7/21/2026 9:04:59 AM,7/21/2026 10:10:01 AM,C000001-000,C000001-001,C000001-001 :,AtBranch,B014,MGC San Fernando CA,WILL CALL,FW04989 GTY SCM,18-20 Toyota Prius/Prius Prime 4D Hatchback (HUD Slr LDWS Rn/Light Snsr,1.00000,1.00000,322.34000,322.34000,0.00000,322.34000,,,',
        'S74641003-0,S74641003-2,7/21/2026 9:04:59 AM,7/23/2026 10:22:36 AM,C000001-000,C000001-001,C000001-001 :,AtBranch,B014,MGC San Fernando CA,WILL CALL,FW02281 GBN FYG,02-06 Nissan Altima 4D Sedan (Slr),1.00000,1.00000,68.80000,68.80000,0.00000,68.80000,,,',
        'S74671852-0,S74671852-1,7/22/2026 1:35:49 PM,7/23/2026 10:22:36 AM,C000001-000,C000001-001,C000001-001 : alex,AtBranch,B014,MGC San Fernando CA,WILL CALL,FW02000 GBN FYG,96-03 Nissan Pathfinder | Infiniti QX4 4D U,1.00000,1.00000,81.99000,81.99000,0.00000,81.99000,,,',
        'S74671852-0,S74671852-2,7/22/2026 1:35:49 PM,7/23/2026 10:22:37 AM,C000001-000,C000001-001,C000001-001 : alex,AtBranch,B014,MGC San Fernando CA,WILL CALL,WKT F2000 GOLD,96-04 Nissan Pathfinder/Infiniti QX4(97-03) windshield molding kit-2pcs(top,1.00000,1.00000,17.94000,17.94000,0.00000,17.94000,,,',
    ];

    $csv = $this->csv;
    foreach ($rowTails as $original) {
        $updated = str_replace(',,,', ',I09988776-0,7/26/2026 12:00:00 AM,', $original);
        $csv = str_replace($original, $updated, $csv);
    }

    $this->importer->import($csv, $this->actor, 'order_history_sample_v2.csv');

    // Every one of the 5 lines now points at the SAME (promoted) document —
    // no duplicate created for this cut date.
    foreach ($sharedOrderLines as $orderLine) {
        $line = VendorDocumentLine::where('order_line', $orderLine)->firstOrFail();
        expect($line->vendor_document_id)->toBe($openDocumentId)
            ->and($line->invoice_number)->toBe('I09988776-0');
    }
    expect(VendorDocument::where('cut_date', $cutDate)->count())->toBe(1);

    $document = VendorDocument::findOrFail($openDocumentId);
    expect($document->document_number)->toBe('I09988776-0')
        ->and($document->line_count)->toBe(5);
});

it('splits an uninvoiced sibling into its own open document when only PART of its week has been invoiced', function () {
    // The inverse of the promotion above: only ONE of the two lines sharing
    // a cut date gets an invoice number; the other must NOT ride along onto
    // that now-numbered document (see the importer's class doc invariant).
    Carbon::setTestNow(Carbon::parse('2026-08-10 09:00', 'America/Los_Angeles'));

    $this->importer->import($this->csv, $this->actor, 'order_history_sample.csv');

    $promoted = VendorDocumentLine::where('order_line', 'S74628731-1')->firstOrFail();
    $sibling = VendorDocumentLine::where('order_line', 'S74641003-1')->firstOrFail();
    expect($promoted->cut_date)->toBe($sibling->cut_date)
        ->and($promoted->vendor_document_id)->toBe($sibling->vendor_document_id); // same open doc, pre-promotion

    $csv = str_replace(
        'S74628731-0,S74628731-1,7/20/2026 1:56:49 PM,7/20/2026 4:42:45 PM,C000001-000,C000001-001,C000001-001 : jose,AtBranch,B014,MGC San Fernando CA,WILL CALL,FW06451 GTY FYG,22-25 Audi Q8 E-Tron 4D U (Slr Adaptive Cruise Ctrl w/Traffic Jam Asst LK,1.00000,1.00000,280.00000,280.00000,0.00000,280.00000,,,',
        'S74628731-0,S74628731-1,7/20/2026 1:56:49 PM,7/20/2026 4:42:45 PM,C000001-000,C000001-001,C000001-001 : jose,AtBranch,B014,MGC San Fernando CA,WILL CALL,FW06451 GTY FYG,22-25 Audi Q8 E-Tron 4D U (Slr Adaptive Cruise Ctrl w/Traffic Jam Asst LK,1.00000,1.00000,280.00000,280.00000,0.00000,280.00000,I09988776-0,7/26/2026 12:00:00 AM,',
        $this->csv
    );

    $this->importer->import($csv, $this->actor, 'order_history_sample_v2.csv');

    $promoted->refresh();
    $sibling->refresh();

    expect($promoted->invoice_number)->toBe('I09988776-0')
        ->and($sibling->invoice_number)->toBeNull()
        // They no longer share a document: the invoiced one is on the
        // numbered document, the uninvoiced one split onto a fresh open one.
        ->and($sibling->vendor_document_id)->not->toBe($promoted->vendor_document_id);

    $numberedDocument = VendorDocument::findOrFail($promoted->vendor_document_id);
    $openDocument = VendorDocument::findOrFail($sibling->vendor_document_id);

    expect($numberedDocument->document_number)->toBe('I09988776-0')
        ->and($openDocument->document_number)->toBeNull()
        ->and($numberedDocument->cut_date)->toBe($openDocument->cut_date);
});

// ---- regression: the orphan-line / already-closed-week bug ----

it('never lets an orphan line with a blank Confirmed_Date attach to an already-numbered invoice for the same week', function () {
    // S74605692-1 has NO Confirmed_Date and NO Invoice_Number in the fixture.
    // Its Order_Date (7/18/2026, Saturday) would compute to cut date
    // 2026-07-19 — the EXACT cut date of the real invoice I05071741-0 (16
    // real lines, verified sum $1,468.72). Freezing "today" inside that same
    // week makes this the maximally adversarial case: even when the current
    // open period's own cut date ALSO happens to be 2026-07-19, the line
    // must still never land on the numbered document.
    Carbon::setTestNow(Carbon::parse('2026-07-17 09:00', 'America/Los_Angeles'));

    $this->importer->import($this->csv, $this->actor, 'order_history_sample.csv');

    $invoice = VendorDocument::where('document_number', 'I05071741-0')->firstOrFail();
    expect($invoice->cut_date)->toBe('2026-07-19')
        ->and($invoice->line_count)->toBe(16)
        ->and($invoice->net_amount_cents)->toBe(146872); // $1,468.72 — verified against the real charge/invoice

    $orphan = VendorDocumentLine::where('order_line', 'S74605692-1')->firstOrFail();
    expect($orphan->vendor_document_id)->not->toBe($invoice->id)
        ->and($invoice->lines()->pluck('order_line'))->not->toContain('S74605692-1');
});

it('parks the orphan on the current OPEN period and flags it, instead of guessing a historical week', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-17 09:00', 'America/Los_Angeles'));

    $this->importer->import($this->csv, $this->actor, 'order_history_sample.csv');

    $orphan = VendorDocumentLine::where('order_line', 'S74605692-1')->firstOrFail();
    expect($orphan->needs_confirmation_date)->toBeTrue()
        ->and($orphan->confirmed_date)->toBeNull();

    $document = VendorDocument::findOrFail($orphan->vendor_document_id);
    expect($document->document_number)->toBeNull()
        ->and($document->cut_date)->toBe(BillingCycle::cutDate('2026-07-17'));
});

it('surfaces how many rows needed confirmation on the VendorImport row', function () {
    // S74605692-1 and S74667976-1 both have a blank Confirmed_Date.
    $import = $this->importer->import($this->csv, $this->actor, 'order_history_sample.csv');

    expect($import->lines_needing_confirmation_date)->toBe(2);
});

it('never puts a line under a numbered document whose invoice_number differs from the document number (structural invariant)', function () {
    // This is the assertion that would have caught the original bug: it
    // doesn't hand-pick a scenario, it checks EVERY numbered document
    // against EVERY one of its own lines, including the null case (an
    // uninvoiced line must never sit under a numbered document at all).
    Carbon::setTestNow(Carbon::parse('2026-07-17 09:00', 'America/Los_Angeles'));

    $this->importer->import($this->csv, $this->actor, 'order_history_sample.csv');

    $numberedDocuments = VendorDocument::whereNotNull('document_number')->with('lines')->get();
    expect($numberedDocuments)->not->toBeEmpty();

    foreach ($numberedDocuments as $document) {
        foreach ($document->lines as $line) {
            expect($line->invoice_number)->toBe($document->document_number);
        }
    }
});

// ---- part number normalization ----

it('normalizes both a space-delimited and a glued-together part label to the same base', function () {
    expect(PartNumber::normalizeBase('FW05851 GTY FYG'))->toBe('FW05851')
        ->and(PartNumber::normalizeBase('FB30470YPY'))->toBe('FB30470');
});

// ---- vehicle parsing ----

it('parses a closed year-range vehicle description', function () {
    $vehicle = PartDescriptionParser::parseVehicle('19-25 Toyota RAV4 4D U (Slr 3VF Acstc Intrlyr LDWS Ped Avoidance Infra');

    expect($vehicle)->toBe(['years_from' => 2019, 'years_to' => 2025, 'make' => 'Toyota', 'model' => 'RAV4']);
});

it('parses an open-ended (still-current) year-range vehicle description', function () {
    $vehicle = PartDescriptionParser::parseVehicle('22- Mercedes C-Class 4D Sedan R/R (Slr 1 Hole)');

    expect($vehicle)->toBe(['years_from' => 2022, 'years_to' => null, 'make' => 'Mercedes', 'model' => 'C-Class']);
});

it('parses an open-ended vehicle description with the double-space-after-dash typo', function () {
    $vehicle = PartDescriptionParser::parseVehicle('23-  Mercedes GLC-Class 4D U (Ant Htd 1 Hole Slr TV Ant)');

    expect($vehicle)->toBe(['years_from' => 2023, 'years_to' => null, 'make' => 'Mercedes', 'model' => 'GLC-Class']);
});

it('parses a single-model-year (no dash) vehicle description', function () {
    $vehicle = PartDescriptionParser::parseVehicle('2024 Lincoln Aviator 4D U (HUD Slr Acstc Intrlyr Adaptive Cruise Ctrl T');

    expect($vehicle)->toBe(['years_from' => 2024, 'years_to' => 2024, 'make' => 'Lincoln', 'model' => 'Aviator']);
});

it('leaves every vehicle field null for a consumable with no vehicle prefix', function () {
    $vehicle = PartDescriptionParser::parseVehicle('26" MGG Platinum Plus Silicone Scrubblade Wiper Blade');

    expect($vehicle)->toBe(['years_from' => null, 'years_to' => null, 'make' => null, 'model' => null]);
});

it('leaves the vehicle fields null on the imported consumable fixture rows', function () {
    $this->importer->import($this->csv, $this->actor, 'order_history_sample.csv');

    $wiperBlade = VendorDocumentLine::where('order_line', 'S73381118-2')->firstOrFail();

    expect($wiperBlade->vehicle_make)->toBeNull()
        ->and($wiperBlade->vehicle_years_from)->toBeNull();
});
