<?php

use App\Enums\BankMatchKind;
use App\Enums\VendorDocumentType;
use App\Models\BankTransaction;
use App\Models\PlaidItem;
use App\Models\VendorDocument;
use App\Models\VendorPayment;
use App\Services\BankMatcher;
use App\Services\Vendors\Igc\IgcChargeReconciler;
use Illuminate\Support\Str;

/**
 * Covers App\Services\Vendors\Igc\IgcChargeReconciler's date-grouping rule
 * (§0/C1 — the single most important correction in the build spec) against
 * the 8 real owner-validated charge groups, plus the zero-tolerance / widen /
 * AUTH-subgroup-guard / no-group paths. All amounts here are the REAL
 * verified sums (validated against the actual Chase export before the
 * fixture file was removed from disk mid-session — see
 * IgcInvoiceHistoryImportTest's fixture note); the individual invoice
 * numbers/splits within a group are illustrative, since only the aggregate
 * group composition was captured.
 */
beforeEach(function () {
    $this->item = PlaidItem::create([
        'item_id' => 'item-igc-1',
        'access_token' => 'access-test',
        'institution_name' => 'Chase',
    ]);

    $this->reconciler = app(IgcChargeReconciler::class);
});

function igcDoc(array $overrides = []): VendorDocument
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

function igcPayment(VendorDocument $doc, array $overrides = []): VendorPayment
{
    return VendorPayment::create(array_merge([
        'supplier' => 'igc',
        'vendor_document_id' => $doc->id,
        'kind' => 'payment',
        'payment_date' => $doc->document_date,
        'amount_cents' => $doc->net_amount_cents,
        'auth_code' => null,
        'memo_raw' => 'Payment of test',
        'fingerprint' => (string) Str::uuid(),
    ], $overrides));
}

function igcCharge(array $overrides = []): BankTransaction
{
    return BankTransaction::create(array_merge([
        'plaid_item_id' => test()->item->id,
        'plaid_transaction_id' => 'igc-txn-'.fake()->unique()->uuid(),
        'plaid_account_id' => 'acc-1',
        'amount_cents' => 10000, // positive = money OUT (a card charge)
        'date' => '2026-07-01',
        'name' => 'IGC LOS ANGELES',
        'merchant_name' => 'Import Glass',
        'pending' => false,
    ], $overrides));
}

// ---- §0/C1 regression: DATE grouping, not AUTH# grouping ----

it('matches the real 03/16 charge of $178.49 as ONE date group — the AUTH# grouping regression', function () {
    // AUTH 05507G -> 2 invoices -> $100.25; one no-AUTH memo -> 1 invoice -> $78.24.
    // AUTH-first grouping would produce a $100.25 group and a $78.24 group,
    // NEITHER equal to the real $178.49 charge. Date grouping sums all three.
    $a = igcDoc(['document_number' => 'A1', 'net_amount_cents' => 5525]);
    $b = igcDoc(['document_number' => 'A2', 'net_amount_cents' => 4500]);
    $c = igcDoc(['document_number' => 'A3', 'net_amount_cents' => 7824]);

    igcPayment($a, ['payment_date' => '2026-03-16', 'amount_cents' => 5525, 'auth_code' => '05507G']);
    igcPayment($b, ['payment_date' => '2026-03-16', 'amount_cents' => 4500, 'auth_code' => '05507G']);
    igcPayment($c, ['payment_date' => '2026-03-16', 'amount_cents' => 7824, 'auth_code' => null]);

    $txn = igcCharge(['amount_cents' => 17849, 'date' => '2026-03-16']);

    $resolution = $this->reconciler->resolve($txn);

    expect($resolution->state)->toBe('matched')
        ->and($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($txn->matches)->toHaveCount(3)
        ->and($txn->matches->pluck('kind')->unique()->first())->toBe(BankMatchKind::VendorInvoice)
        ->and(VendorDocument::whereIn('id', [$a->id, $b->id, $c->id])->whereNotNull('charge_matched_at')->count())->toBe(3);
});

it('matches every one of the 8 real owner-validated charge dates exactly', function () {
    $scenarios = [
        ['date' => '2026-03-05', 'n' => 9, 'cents' => 96390],
        ['date' => '2026-04-17', 'n' => 10, 'cents' => 102021],
        ['date' => '2026-04-28', 'n' => 5, 'cents' => 108596],
        ['date' => '2026-05-05', 'n' => 4, 'cents' => 33696],
        ['date' => '2026-07-03', 'n' => 15, 'cents' => 100173],
        ['date' => '2026-07-07', 'n' => 3, 'cents' => 76524],
        ['date' => '2026-07-20', 'n' => 10, 'cents' => 121115],
    ];

    foreach ($scenarios as $s) {
        // Split the group total across N equal-ish payments (illustrative —
        // only the real aggregate is verified) on the same real date.
        $each = intdiv($s['cents'], $s['n']);
        $remainder = $s['cents'] - ($each * $s['n']);

        for ($i = 0; $i < $s['n']; $i++) {
            $doc = igcDoc(['document_number' => $s['date'].'-'.$i, 'net_amount_cents' => $each]);
            igcPayment($doc, [
                'payment_date' => $s['date'],
                'amount_cents' => $each + ($i === 0 ? $remainder : 0),
            ]);
        }

        $txn = igcCharge(['amount_cents' => $s['cents'], 'date' => $s['date']]);

        expect($this->reconciler->resolve($txn)->state)->toBe('matched');
    }
});

// ---- Zero tolerance ----

it('a 1-cent difference does NOT match — zero tolerance', function () {
    $doc = igcDoc(['net_amount_cents' => 10000]);
    igcPayment($doc, ['payment_date' => '2026-07-01', 'amount_cents' => 10000]);

    $txn = igcCharge(['amount_cents' => 10001, 'date' => '2026-07-01']);

    $resolution = $this->reconciler->resolve($txn);

    expect($resolution->state)->not->toBe('matched')
        ->and($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED);
});

// ---- Rule 2: widen +/- N days ----

it('widens to a date within the window whose group sums exactly — suggested, never auto-confirmed', function () {
    $doc = igcDoc(['net_amount_cents' => 5000]);
    igcPayment($doc, ['payment_date' => '2026-07-03', 'amount_cents' => 5000]); // 2 days off the charge's own date

    $txn = igcCharge(['amount_cents' => 5000, 'date' => '2026-07-01']); // nothing recorded ON this date

    $resolution = $this->reconciler->resolve($txn);

    expect($resolution->state)->toBe('suggested')
        ->and($resolution->groupDate)->toBe('2026-07-03')
        ->and($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and(VendorDocument::whereNotNull('charge_matched_at')->count())->toBe(0); // suggested, not confirmed
});

// ---- Rule 3 (guard): AUTH# subgroup ----

it('falls back to an AUTH# subgroup when the exact date carries two distinct AUTH#s and neither full-date nor widened matches', function () {
    $a = igcDoc(['net_amount_cents' => 4000]);
    $b = igcDoc(['net_amount_cents' => 6000]);

    igcPayment($a, ['payment_date' => '2026-07-01', 'amount_cents' => 4000, 'auth_code' => 'AAAA1']);
    igcPayment($b, ['payment_date' => '2026-07-01', 'amount_cents' => 6000, 'auth_code' => 'BBBB2']);

    // Full date sums to 10000; the charge only covers the AAAA1 subgroup.
    $txn = igcCharge(['amount_cents' => 4000, 'date' => '2026-07-01']);

    $resolution = $this->reconciler->resolve($txn);

    expect($resolution->state)->toBe('suggested')
        ->and($resolution->groupSumCents)->toBe(4000)
        ->and($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_SUGGESTED);
});

// ---- Rule 4: missing_memo (charge > group) ----

it('reports missing_memo with the exact shortfall when the charge is more than the exact-date group', function () {
    $doc = igcDoc(['net_amount_cents' => 8000]);
    igcPayment($doc, ['payment_date' => '2026-07-01', 'amount_cents' => 8000]);

    $txn = igcCharge(['amount_cents' => 10000, 'date' => '2026-07-01']);

    $resolution = $this->reconciler->resolve($txn);

    expect($resolution->state)->toBe('missing_memo')
        ->and($resolution->shortfallCents)->toBe(2000)
        ->and($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED)
        ->and($txn->matches)->toHaveCount(0);
});

// ---- Rule 5: credit_applied (charge < group) ----

it('reports credit_applied with the exact overage when the charge is less than the exact-date group', function () {
    $doc = igcDoc(['net_amount_cents' => 12000]);
    igcPayment($doc, ['payment_date' => '2026-07-01', 'amount_cents' => 12000]);

    $txn = igcCharge(['amount_cents' => 10000, 'date' => '2026-07-01']);

    $resolution = $this->reconciler->resolve($txn);

    expect($resolution->state)->toBe('credit_applied')
        ->and($resolution->overageCents)->toBe(2000)
        ->and($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED);
});

// ---- Rule 6 / I1: no_group ----

it('reports no_group (alert I1) when nothing is recorded for the charge date at all', function () {
    $txn = igcCharge(['amount_cents' => 5000, 'date' => '2026-07-01']);

    expect($this->reconciler->resolve($txn)->state)->toBe('no_group');
});

// ---- BankMatcher wiring + non-IGC regression ----

it('routes an IGC charge through the reconciler via BankMatcher::matchOne, not findPartPurchase', function () {
    $doc = igcDoc(['net_amount_cents' => 10000]);
    igcPayment($doc, ['payment_date' => '2026-07-01', 'amount_cents' => 10000]);

    $txn = igcCharge(['amount_cents' => 10000, 'date' => '2026-07-01']);

    $status = app(BankMatcher::class)->matchOne($txn);

    expect($status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($txn->refresh()->matches->first()->kind)->toBe(BankMatchKind::VendorInvoice);
});
