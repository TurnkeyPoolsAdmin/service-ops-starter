<?php

use App\Models\BankTransaction;
use App\Models\BankTransactionMatch;
use App\Models\LegacyManualPayment;
use App\Models\PlaidItem;
use App\Services\LegacyPaymentMatcher;

beforeEach(function () {
    test()->item = PlaidItem::create([
        'item_id' => 'item-legacy', 'access_token' => 'access-test', 'institution_name' => 'Chase',
    ]);
    test()->matcher = app(LegacyPaymentMatcher::class);
});

function legacyRow(array $overrides = []): LegacyManualPayment
{
    return LegacyManualPayment::create(array_merge([
        'source_row' => rand(1000, 999999),
        'payment_date' => '2025-10-15',
        'customer_name' => 'Jane Smith',
        'paid_cents' => 50000,
        'charged_cents' => 50000,
        'payment_method' => 'Zelle',
        'status' => LegacyManualPayment::STATUS_PENDING,
    ], $overrides));
}

function legacyTxn(array $overrides = []): BankTransaction
{
    return BankTransaction::create(array_merge([
        'plaid_item_id' => test()->item->id,
        'plaid_transaction_id' => 'txn-'.fake()->unique()->uuid(),
        'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking',
        'amount_cents' => -50000, // deposit
        'date' => '2025-10-16',
        'name' => 'Zelle from Jane Smith',
        'pending' => false,
        'match_status' => BankTransaction::STATUS_UNMATCHED,
    ], $overrides));
}

it('matches a single unambiguous candidate and marks it suggested, not auto-confirmed', function () {
    $row = legacyRow();
    $txn = legacyTxn();

    $result = test()->matcher->matchOne($row, dryRun: false);

    expect($result->state)->toBe('matched')
        ->and($txn->fresh()->match_status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and($row->fresh()->status)->toBe(LegacyManualPayment::STATUS_MATCHED);

    $match = BankTransactionMatch::where('legacy_manual_payment_id', $row->id)->firstOrFail();
    expect($match->source)->toBe('legacy_import')
        ->and($match->job_id)->toBeNull()
        ->and($match->job_payment_id)->toBeNull()
        ->and($match->bank_transaction_id)->toBe($txn->id);

    expect($row->fresh()->bank_transaction_match_id)->toBe($match->id);
});

it('does not write anything on a dry run', function () {
    $row = legacyRow();
    $txn = legacyTxn();

    $result = test()->matcher->matchOne($row, dryRun: true);

    expect($result->state)->toBe('matched')
        ->and($result->transaction->id)->toBe($txn->id);

    expect($txn->fresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED)
        ->and($row->fresh()->status)->toBe(LegacyManualPayment::STATUS_PENDING)
        ->and(BankTransactionMatch::count())->toBe(0);
});

it('reports no_candidate and marks the row unmatched on a live run when nothing fits', function () {
    $row = legacyRow(['paid_cents' => 99999999]); // nothing at the bank is anywhere close

    $result = test()->matcher->matchOne($row, dryRun: false);

    expect($result->state)->toBe('no_candidate')
        ->and($row->fresh()->status)->toBe(LegacyManualPayment::STATUS_UNMATCHED);
});

/**
 * 2026-07-30 real incident: Misti Schwartz's $425.00 payment auto-matched to
 * a $427.90 Zelle from "AKIRA NAKAMURA" (Bryan Sprow's $468.00 auto-matched
 * to "CINESKY LLC" is the same shape), because a SINGLE amount/date
 * candidate used to skip the name check entirely. Same amount/date
 * proximity, zero name correspondence — must now be held for a human, not
 * auto-linked, however lonely the candidate is.
 */
it('does not auto-match the sole amount/date candidate when its name has zero correspondence to the customer', function () {
    $row = legacyRow(['customer_name' => 'Misti Schwartz', 'paid_cents' => 42500]);
    $txn = legacyTxn(['name' => 'Zelle payment from AKIRA NAKAMURA', 'amount_cents' => -42790]);

    $result = test()->matcher->matchOne($row, dryRun: false);

    expect($result->state)->toBe('ambiguous')
        ->and($row->fresh()->status)->toBe(LegacyManualPayment::STATUS_UNMATCHED)
        ->and($txn->fresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED)
        ->and(BankTransactionMatch::count())->toBe(0);
});

it('disambiguates multiple amount/date candidates by customer name', function () {
    $row = legacyRow(['customer_name' => 'Bobby Bergin']);
    $wrongCustomer = legacyTxn(['name' => 'Zelle from Someone Else', 'plaid_transaction_id' => 'txn-a']);
    $rightCustomer = legacyTxn(['name' => 'Zelle from Bobby Bergin', 'plaid_transaction_id' => 'txn-b']);

    $result = test()->matcher->matchOne($row, dryRun: false);

    expect($result->state)->toBe('matched')
        ->and($result->transaction->id)->toBe($rightCustomer->id);

    expect($wrongCustomer->fresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED);
});

it('leaves a genuinely ambiguous multi-candidate row unmatched rather than guessing', function () {
    $row = legacyRow(['customer_name' => 'Nondescript Person']);
    $txnC = legacyTxn(['name' => 'Zelle payment', 'plaid_transaction_id' => 'txn-c']);
    $txnD = legacyTxn(['name' => 'Zelle payment', 'plaid_transaction_id' => 'txn-d']);

    $result = test()->matcher->matchOne($row, dryRun: false);

    expect($result->state)->toBe('ambiguous')
        ->and($row->fresh()->status)->toBe(LegacyManualPayment::STATUS_UNMATCHED)
        ->and(BankTransactionMatch::count())->toBe(0)
        ->and($result->candidates->pluck('id')->sort()->values()->all())
        ->toBe(collect([$txnC->id, $txnD->id])->sort()->values()->all());
});

it('never offers an already-matched or already-excluded transaction as a candidate', function () {
    $row = legacyRow();
    $matchedTxn = legacyTxn(['match_status' => BankTransaction::STATUS_MATCHED]);

    $result = test()->matcher->matchOne($row, dryRun: false);
    expect($result->state)->toBe('no_candidate');

    $row2 = legacyRow(['source_row' => 999998]);
    $excludedTxn = legacyTxn(['plaid_transaction_id' => 'txn-excluded']);
    $excludedTxn->excludeAsPretracking('test exclusion');

    $result2 = test()->matcher->matchOne($row2, dryRun: false);
    expect($result2->state)->toBe('no_candidate');
});

// ---- sweepUnexplainedTransactions ----

/**
 * The sweep derives the CSV's date range from MIN/MAX across ALL legacy
 * rows — every sweep test needs at least one row anchoring each end of a
 * range wide enough to bracket whatever transaction dates it tests, or the
 * transaction falls outside the range and is skipped entirely rather than
 * genuinely tested for candidacy.
 */
function anchorLegacyDateRange(): void
{
    legacyRow(['payment_date' => '2025-09-01', 'source_row' => 900001, 'customer_name' => 'Anchor Early']);
    legacyRow(['payment_date' => '2026-04-30', 'source_row' => 900002, 'customer_name' => 'Anchor Late']);
}

it('excludes a deposit with zero candidates anywhere in the legacy CSV', function () {
    anchorLegacyDateRange();
    legacyRow(['payment_date' => '2025-10-01', 'paid_cents' => 30000, 'source_row' => 900003]); // present, but far off in amount/date from the txn below
    $unexplained = legacyTxn(['amount_cents' => -99999, 'date' => '2026-04-01']);

    $result = test()->matcher->sweepUnexplainedTransactions(dryRun: false);

    expect($result['excluded'])->toBe(1);
    expect($unexplained->fresh()->isExcluded())->toBeTrue()
        ->and($unexplained->fresh()->excluded_reason)->toContain('Pre-tracking era');
});

it('does not exclude a deposit that has a real candidate, even if that candidate matched something else', function () {
    anchorLegacyDateRange();
    $row = legacyRow(['payment_date' => '2026-01-15', 'paid_cents' => 40000, 'source_row' => 900004]);
    $explained = legacyTxn(['amount_cents' => -40000, 'date' => '2026-01-16', 'plaid_transaction_id' => 'txn-explained']);

    $result = test()->matcher->sweepUnexplainedTransactions(dryRun: false);

    expect($result['excluded'])->toBe(0);
    expect($explained->fresh()->isExcluded())->toBeFalse();
});

it('never counts a no_match_expected (cash/paid-$0) legacy row as a candidate for the sweep', function () {
    anchorLegacyDateRange();
    legacyRow(['payment_date' => '2026-02-01', 'paid_cents' => 35000, 'status' => LegacyManualPayment::STATUS_NO_MATCH_EXPECTED, 'source_row' => 900005]);
    $txn = legacyTxn(['amount_cents' => -35000, 'date' => '2026-02-02']);

    $result = test()->matcher->sweepUnexplainedTransactions(dryRun: false);

    expect($result['excluded'])->toBe(1); // the no_match_expected row doesn't count as an explanation
    expect($txn->fresh()->isExcluded())->toBeTrue();
});

it('dry-run reports the sweep without writing anything', function () {
    anchorLegacyDateRange();
    legacyTxn(['amount_cents' => -12345, 'date' => '2026-03-01']);

    $result = test()->matcher->sweepUnexplainedTransactions(dryRun: true);

    expect($result['excluded'])->toBe(1);
    expect(BankTransaction::excluded()->count())->toBe(0);
});

it('excludeAsPretracking removes a transaction from the active needs-review queue, and is reversible', function () {
    $txn = legacyTxn();
    expect(BankTransaction::needsReview()->pluck('id'))->toContain($txn->id);

    $txn->excludeAsPretracking('test');
    expect(BankTransaction::needsReview()->pluck('id'))->not->toContain($txn->id)
        ->and(BankTransaction::excluded()->pluck('id'))->toContain($txn->id);

    $txn->restoreFromExclusion();
    expect(BankTransaction::needsReview()->pluck('id'))->toContain($txn->id)
        ->and($txn->fresh()->isExcluded())->toBeFalse();
});
