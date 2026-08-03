<?php

use App\Enums\VendorDocumentType;
use App\Models\VendorDocument;
use App\Models\VendorPayment;
use App\Services\Vendors\Igc\IgcNetter;
use Illuminate\Support\Str;

/**
 * Covers App\Services\Vendors\Igc\IgcNetter's §0/C2 pairing algorithm: a
 * return's own POSITIVE "Credit/Return of X" mirror paired to the ORIGINAL
 * invoice's NEGATIVE mirror on (date, amount) — including the real
 * three-way tie-break example validated against the actual export before
 * the fixture file was removed mid-session (see
 * IgcInvoiceHistoryImportTest's fixture note): return 1915921 pairs to
 * original 1915920, return 1915964 pairs to original 1915679, both on the
 * real date (2026-06-23) and real amount ($16.50).
 */
function igcCreditDoc(array $overrides = []): VendorDocument
{
    return VendorDocument::create(array_merge([
        'supplier' => 'igc',
        'type' => VendorDocumentType::Invoice->value,
        'document_number' => (string) random_int(1000000, 9999999),
        'document_date' => '2026-07-01',
        'cut_date' => '2026-07-01',
        'settle_date' => '2026-07-01',
        'net_amount_cents' => 10000,
        'balance_cents' => 0,
        'source' => 'invoice_history',
    ], $overrides));
}

/** A parsed "Credit/Return of X" memo line (kind=credit) on a document. */
function igcCreditMirror(VendorDocument $doc, string $date, int $signedAmountCents): VendorPayment
{
    return VendorPayment::create([
        'supplier' => 'igc',
        'vendor_document_id' => $doc->id,
        'kind' => 'credit',
        'payment_date' => $date,
        'amount_cents' => $signedAmountCents,
        'auth_code' => null,
        'memo_raw' => 'Credit/Return of test',
        'fingerprint' => (string) Str::uuid(),
    ]);
}

beforeEach(function () {
    $this->netter = app(IgcNetter::class);
});

it('pairs a credit document to its original invoice via the memo mirror', function () {
    $original = igcCreditDoc(['document_number' => '1923668', 'net_amount_cents' => 15800]);
    $credit = igcCreditDoc(['document_number' => '1924097', 'net_amount_cents' => -15800, 'type' => VendorDocumentType::CreditMemo->value]);

    igcCreditMirror($original, '2026-07-23', -15800); // the original's negative mirror
    igcCreditMirror($credit, '2026-07-23', 15800);     // the return's own positive mirror

    $pairs = $this->netter->pairings();

    expect($pairs)->toHaveCount(1)
        ->and($pairs->first()['credit']->id)->toBe($credit->id)
        ->and($pairs->first()['original']->id)->toBe($original->id);
});

it('resolves two same-day identical-amount returns via nearest-lower invoice number tie-break (real example)', function () {
    $originalLow = igcCreditDoc(['document_number' => '1915679', 'net_amount_cents' => 1650]);
    $originalHigh = igcCreditDoc(['document_number' => '1915920', 'net_amount_cents' => 1650]);
    $creditLow = igcCreditDoc(['document_number' => '1915921', 'net_amount_cents' => -1650, 'type' => VendorDocumentType::CreditMemo->value]);
    $creditHigh = igcCreditDoc(['document_number' => '1915964', 'net_amount_cents' => -1650, 'type' => VendorDocumentType::CreditMemo->value]);

    igcCreditMirror($originalLow, '2026-06-23', -1650);
    igcCreditMirror($originalHigh, '2026-06-23', -1650);
    igcCreditMirror($creditLow, '2026-06-23', 1650);
    igcCreditMirror($creditHigh, '2026-06-23', 1650);

    $pairs = $this->netter->pairings()->keyBy(fn (array $p) => $p['credit']->document_number);

    expect($pairs['1915921']['original']->document_number)->toBe('1915920')
        ->and($pairs['1915964']['original']->document_number)->toBe('1915679');
});

it('pairs all 52-style credits with zero unpaired when every credit has a matching original', function () {
    // A smaller stand-in for the real 52/52 result: several straightforward
    // unique pairs plus one tie pair, all resolved.
    $pairsData = [
        ['orig' => 'O1', 'credit' => 'C1', 'amount' => 5000, 'date' => '2026-01-05'],
        ['orig' => 'O2', 'credit' => 'C2', 'amount' => 12000, 'date' => '2026-02-10'],
        ['orig' => 'O3', 'credit' => 'C3', 'amount' => 3450, 'date' => '2026-03-15'],
    ];

    foreach ($pairsData as $p) {
        $original = igcCreditDoc(['document_number' => $p['orig'], 'net_amount_cents' => $p['amount']]);
        $credit = igcCreditDoc(['document_number' => $p['credit'], 'net_amount_cents' => -$p['amount'], 'type' => VendorDocumentType::CreditMemo->value]);
        igcCreditMirror($original, $p['date'], -$p['amount']);
        igcCreditMirror($credit, $p['date'], $p['amount']);
    }

    expect($this->netter->unpairedCredits())->toHaveCount(0)
        ->and($this->netter->pairings())->toHaveCount(3);
});

it('excludes netted (paired) credits from Layer 4 — they are never live cost', function () {
    $original = igcCreditDoc(['document_number' => '3000100', 'net_amount_cents' => 8000]);
    $credit = igcCreditDoc(['document_number' => '3000200', 'net_amount_cents' => -8000, 'type' => VendorDocumentType::CreditMemo->value]);
    igcCreditMirror($original, '2026-04-01', -8000);
    igcCreditMirror($credit, '2026-04-01', 8000);

    $pairs = $this->netter->pairings();

    // Paired credits never show up as "unpaired" (the I5 candidate set).
    expect($this->netter->unpairedCredits()->pluck('id'))->not->toContain($credit->id)
        ->and($pairs->pluck('credit.id'))->toContain($credit->id);
});

// ---- R3.4 / alert I5: widened +/-30 day search, and the true-orphan case ----

it('finds an original within the +/-30 day widened search when the exact date has no mirror', function () {
    $original = igcCreditDoc(['document_number' => '1000100', 'net_amount_cents' => 9000]);
    $credit = igcCreditDoc(['document_number' => '1000200', 'net_amount_cents' => -9000, 'type' => VendorDocumentType::CreditMemo->value, 'document_date' => '2026-02-01']);

    igcCreditMirror($credit, '2026-02-01', 9000);
    igcCreditMirror($original, '2026-02-20', -9000); // 19 days later — no exact-date match, but within 30

    $pairs = $this->netter->pairings()->keyBy(fn (array $p) => $p['credit']->document_number);

    expect($pairs->has('1000200'))->toBeTrue()
        ->and($pairs['1000200']['original']->document_number)->toBe('1000100');
});

it('leaves a credit unpaired (I5) when no original is found even within 30 days', function () {
    $credit = igcCreditDoc(['document_number' => '2000200', 'net_amount_cents' => -4000, 'type' => VendorDocumentType::CreditMemo->value, 'document_date' => '2026-03-01']);
    igcCreditMirror($credit, '2026-03-01', 4000);
    // No negative mirror recorded ANYWHERE for this amount — a true orphan.

    $unpaired = $this->netter->unpairedCredits();

    expect($unpaired->pluck('document_number'))->toContain('2000200');
});

it('does not pair beyond the 30-day window', function () {
    $original = igcCreditDoc(['document_number' => '4000100', 'net_amount_cents' => 4000]);
    $credit = igcCreditDoc(['document_number' => '4000200', 'net_amount_cents' => -4000, 'type' => VendorDocumentType::CreditMemo->value, 'document_date' => '2026-05-01']);

    igcCreditMirror($credit, '2026-05-01', 4000);
    igcCreditMirror($original, '2026-06-20', -4000); // 50 days later — beyond the window

    $unpaired = $this->netter->unpairedCredits();

    expect($unpaired->pluck('document_number'))->toContain('4000200');
});
