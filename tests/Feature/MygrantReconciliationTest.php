<?php

use App\Enums\BankMatchKind;
use App\Enums\VendorDocumentType;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\PlaidItem;
use App\Models\VendorDocument;
use App\Services\BankMatcher;
use App\Services\Vendors\VendorChargeReconciler;
use Database\Seeders\EntitySeeder;

/**
 * Covers App\Services\Vendors\VendorChargeReconciler's resolution order
 * (rules 1-5) against the four real production charge/document scenarios
 * documented in the build spec, plus the subset-sum / short / over / ambiguous
 * edge cases and the non-Mygrant regression on App\Services\BankMatcher.
 */
beforeEach(function () {
    $this->item = PlaidItem::create([
        'item_id' => 'item-mygrant-1',
        'access_token' => 'access-test',
        'institution_name' => 'Chase',
    ]);

    $this->actor = Employee::create([
        'name' => 'Finance Op', 'email' => 'finance@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);

    $this->reconciler = app(VendorChargeReconciler::class);
});

function mygrantDoc(array $overrides = []): VendorDocument
{
    return VendorDocument::create(array_merge([
        'supplier' => 'mygrant',
        'type' => VendorDocumentType::Invoice->value,
        'document_number' => 'I0000000-'.VendorDocument::count(),
        'document_date' => '2026-06-28',
        'cut_date' => '2026-06-28',
        'settle_date' => '2026-07-01',
        'net_amount_cents' => 100000,
        'line_count' => 1,
        'source' => 'order_history',
    ], $overrides));
}

function mygrantCharge(array $overrides = []): BankTransaction
{
    return BankTransaction::create(array_merge([
        'plaid_item_id' => test()->item->id,
        'plaid_transaction_id' => 'mygrant-txn-'.fake()->unique()->uuid(),
        'plaid_account_id' => 'acc-1',
        'amount_cents' => 100000, // positive = money OUT (a card charge)
        'date' => '2026-07-01',
        'name' => 'MYGRANT GLASS CO #018',
        'merchant_name' => 'Mygrant Glass',
        'pending' => false,
    ], $overrides));
}

// ---- Real production scenarios (rule 1: full open set sums exactly) ----

it('matches 07/01: invoice minus its own same-day credit memo', function () {
    mygrantDoc(['document_number' => 'I05061100-0', 'net_amount_cents' => 286695, 'settle_date' => '2026-07-01']);
    mygrantDoc(['document_number' => 'I05061101-0', 'type' => VendorDocumentType::CreditMemo->value, 'net_amount_cents' => -54799, 'settle_date' => '2026-07-01']);

    $txn = mygrantCharge(['amount_cents' => 231896, 'date' => '2026-07-01']);

    $resolution = $this->reconciler->resolve($txn);

    expect($resolution->state)->toBe('matched')
        ->and($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($txn->matches)->toHaveCount(2)
        ->and($txn->matches->pluck('kind')->unique()->first())->toBe(BankMatchKind::VendorInvoice)
        ->and(VendorDocument::whereNotNull('charge_matched_at')->count())->toBe(2);
});

it('matches 07/08: one charge settling TWO documents that share a settle date (month-end mid-week cut)', function () {
    // BillingCycle::settleDate() itself lands the 06/30 (Tuesday) month-end
    // cut on 07/08 — cut+3 days = 07/03 (Friday), not a Wednesday, so it
    // rolls to the NEXT Wednesday. That's a full week later than the
    // ordinary Sunday-cut case (Sun+3 always lands on a Wednesday already),
    // which is what makes this invoice share a settle_date with the
    // following week's regular invoice — both real numbers, verified against
    // the actual invoice PDFs and the real Chase charge.
    mygrantDoc(['document_number' => 'I05061200-0', 'net_amount_cents' => 126178, 'cut_date' => '2026-06-30', 'settle_date' => '2026-07-08']);
    mygrantDoc(['document_number' => 'I05061201-0', 'net_amount_cents' => 19858, 'cut_date' => '2026-07-05', 'settle_date' => '2026-07-08']);

    $txn = mygrantCharge(['amount_cents' => 146036, 'date' => '2026-07-08']);

    $resolution = $this->reconciler->resolve($txn);

    expect($resolution->state)->toBe('matched')
        ->and($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($txn->matches)->toHaveCount(2);
});

it('matches 07/15: invoice minus its sequentially-numbered credit memo', function () {
    mygrantDoc(['document_number' => 'I05061146-0', 'net_amount_cents' => 430862, 'settle_date' => '2026-07-15']);
    mygrantDoc(['document_number' => 'I05061147-0', 'type' => VendorDocumentType::CreditMemo->value, 'net_amount_cents' => -60706, 'settle_date' => '2026-07-15']);

    $txn = mygrantCharge(['amount_cents' => 370156, 'date' => '2026-07-15']);

    expect($this->reconciler->resolve($txn)->state)->toBe('matched');
});

it('matches 07/22: invoice minus credit, a fourth real scenario', function () {
    mygrantDoc(['document_number' => 'I05061300-0', 'net_amount_cents' => 146872, 'settle_date' => '2026-07-22']);
    mygrantDoc(['document_number' => 'I05061301-0', 'type' => VendorDocumentType::CreditMemo->value, 'net_amount_cents' => -10270, 'settle_date' => '2026-07-22']);

    $txn = mygrantCharge(['amount_cents' => 136602, 'date' => '2026-07-22']);

    expect($this->reconciler->resolve($txn)->state)->toBe('matched');
});

// ---- Rule 4: short (missing credit memo) ----

it('reports a short charge with the EXACT expected credit-memo amount, and does not match', function () {
    // Only the invoice has been ingested — the credit memo that would close
    // this out hasn't been uploaded yet.
    mygrantDoc(['document_number' => 'I05061146-0', 'net_amount_cents' => 430862, 'settle_date' => '2026-07-15']);

    $txn = mygrantCharge(['amount_cents' => 370156, 'date' => '2026-07-15']);

    $resolution = $this->reconciler->resolve($txn);

    expect($resolution->state)->toBe('short')
        ->and($resolution->shortfallCents)->toBe(60706) // exactly the missing credit memo
        ->and($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED)
        ->and($txn->matches)->toHaveCount(0);
});

it('accepting the difference lets a previously-short charge match', function () {
    $doc = mygrantDoc(['document_number' => 'I05061146-0', 'net_amount_cents' => 430862, 'settle_date' => '2026-07-15']);
    $txn = mygrantCharge(['amount_cents' => 370156, 'date' => '2026-07-15']);

    $first = $this->reconciler->resolve($txn);
    expect($first->state)->toBe('short');

    $this->reconciler->acceptDifference($doc, -$first->shortfallCents, 'Old week, no credit memo will ever come.', $this->actor);

    $second = $this->reconciler->resolve($txn);

    expect($second->state)->toBe('matched')
        ->and($doc->refresh()->accepted_difference_cents)->toBe(-60706)
        ->and($doc->accepted_by)->toBe($this->actor->id);
});

// ---- Rule 5: over (never auto-resolved) ----

it('never auto-resolves a charge higher than every open document, and reports the overage', function () {
    mygrantDoc(['document_number' => 'I05061400-0', 'net_amount_cents' => 100000, 'settle_date' => '2026-07-01']);

    $txn = mygrantCharge(['amount_cents' => 150000, 'date' => '2026-07-01']);

    $resolution = $this->reconciler->resolve($txn);

    expect($resolution->state)->toBe('over')
        ->and($resolution->overageCents)->toBe(50000)
        ->and($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED)
        ->and($txn->matches)->toHaveCount(0);
});

// ---- Rule 3: unique subset-sum suggests, never auto-confirms ----

it('suggests a unique exact-sum subset among several open documents, without auto-confirming', function () {
    // Three open documents (a backfill scenario); only doc A + doc C sum to
    // the charge exactly — doc B is unrelated and will be covered by a LATER
    // charge, not this one.
    $a = mygrantDoc(['document_number' => 'A', 'net_amount_cents' => 50000, 'settle_date' => '2026-07-01']);
    $b = mygrantDoc(['document_number' => 'B', 'net_amount_cents' => 30000, 'settle_date' => '2026-07-01']);
    $c = mygrantDoc(['document_number' => 'C', 'net_amount_cents' => 25000, 'settle_date' => '2026-07-01']);

    $txn = mygrantCharge(['amount_cents' => 75000, 'date' => '2026-07-01']); // A + C, not A+B+C (105000) nor B+C (55000)

    $resolution = $this->reconciler->resolve($txn);

    expect($resolution->state)->toBe('suggested')
        ->and($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and($txn->matches)->toHaveCount(2)
        ->and($txn->matches->pluck('vendor_document_id')->sort()->values()->all())->toBe(collect([$a->id, $c->id])->sort()->values()->all())
        ->and(VendorDocument::whereNotNull('charge_matched_at')->count())->toBe(0); // suggested, not confirmed
});

it('leaves an ambiguous subset-sum unmatched — more than one combination ties', function () {
    // Two disjoint pairs both sum to the charge: A+B and C+D.
    mygrantDoc(['document_number' => 'A', 'net_amount_cents' => 40000, 'settle_date' => '2026-07-01']);
    mygrantDoc(['document_number' => 'B', 'net_amount_cents' => 20000, 'settle_date' => '2026-07-01']);
    mygrantDoc(['document_number' => 'C', 'net_amount_cents' => 35000, 'settle_date' => '2026-07-01']);
    mygrantDoc(['document_number' => 'D', 'net_amount_cents' => 25000, 'settle_date' => '2026-07-01']);

    $txn = mygrantCharge(['amount_cents' => 60000, 'date' => '2026-07-01']);

    $resolution = $this->reconciler->resolve($txn);

    expect($resolution->state)->toBe('ambiguous')
        ->and($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED)
        ->and($txn->matches)->toHaveCount(0);
});

it('reports no_candidates when no Mygrant document is open at all', function () {
    $txn = mygrantCharge(['amount_cents' => 50000]);

    expect($this->reconciler->resolve($txn)->state)->toBe('no_candidates');
});

// ---- BankMatcher wiring + non-Mygrant regression ----

it('routes a Mygrant charge through the reconciler via BankMatcher::matchOne, not findPartPurchase', function () {
    mygrantDoc(['document_number' => 'I05061500-0', 'net_amount_cents' => 100000, 'settle_date' => '2026-07-01']);
    $txn = mygrantCharge(['amount_cents' => 100000, 'date' => '2026-07-01']);

    $status = app(BankMatcher::class)->matchOne($txn);

    expect($status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($txn->refresh()->matches->first()->kind)->toBe(BankMatchKind::VendorInvoice);
});

it('a non-Mygrant parts-vendor charge still behaves exactly as before (regression)', function () {
    test()->seed(EntitySeeder::class);

    $customer = Customer::firstOrCreate(['phone_e164' => '+18185550099'], ['first_name' => 'Regress']);
    $job = Job::create([
        'job_number' => 'LA-2026-9001',
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Regress Test',
        'status' => 'completed',
        'part_cost_cents' => 18500,
        'created_at' => '2026-06-25 12:00:00',
    ]);

    $txn = mygrantCharge(['amount_cents' => 18500, 'name' => 'PILKINGTON NA', 'merchant_name' => 'Pilkington']);

    $status = app(BankMatcher::class)->matchOne($txn);

    expect($status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and($txn->refresh()->matches->first()->kind)->toBe(BankMatchKind::PartPurchase)
        ->and($txn->matches->first()->job_id)->toBe($job->id);
});
