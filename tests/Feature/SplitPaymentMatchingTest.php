<?php

use App\Enums\BankMatchKind;
use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\PlaidItem;
use App\Services\BankMatcher;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;

/**
 * Coverage for the 2026-07-30 "a job's total paid via two separate bank
 * transfers" fix: BankMatcher::unmatchedPaymentLines() went from a binary
 * "has any match at all" exclusion to a sum-aware one, so a JobPayment line
 * can be matched by MORE THAN ONE BankTransaction as long as their amounts
 * don't exceed the line's own amount_cents (see BankMatcher::
 * remainingCentsForLine()). Reuses the bankTxn() helper declared in
 * BankMatchingTest.php — Pest registers every test file's top-level
 * functions globally.
 *
 * The auto-matcher side is the highest-risk part of this change (per the
 * owner's explicit ask): a line with room left can no longer take
 * disambiguate()'s single-candidate free pass, specifically so a genuinely
 * unrelated customer's deposit that happens to equal the remaining balance
 * doesn't silently auto-suggest against someone else's money.
 */
beforeEach(function () {
    test()->seed([RoleSeeder::class, EntitySeeder::class]);

    test()->item = PlaidItem::create([
        'item_id' => 'item-split-payment', 'access_token' => 'access-test', 'institution_name' => 'Chase',
    ]);

    test()->admin = Employee::create([
        'name' => 'Admin', 'email' => 'split-admin@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    test()->admin->assignRole('super_admin');

    test()->matcher = app(BankMatcher::class);
});

/** A job with ONE $798.00 Zelle payment line — the customer's total, paid via two real $399.00 transfers. */
function splitJob(string $jobNumber, string $customerName, string $phone): array
{
    $customer = Customer::firstOrCreate(['phone_e164' => $phone], ['first_name' => explode(' ', $customerName)[0]]);
    $job = Job::create([
        'job_number' => $jobNumber,
        'entity_id' => Entity::where('code', 'SFV')->value('id'),
        'customer_id' => $customer->id,
        'customer_name_snapshot' => $customerName,
        'status' => 'completed',
        'quote_amount_cents' => 79800,
    ]);
    $line = $job->payments()->create(['amount_cents' => 79800, 'method' => 'zelle', 'collected_at' => '2026-07-08 09:00:00']);

    return [$job, $line];
}

// defaultPaymentLineOptionsFor() is declared globally in StripeDepositWindowTest.php
// (Pest registers every test file's top-level functions before any test runs).

// ---- Auto-matcher: same customer's second transfer should auto-suggest ----

it('auto-matches a second same-customer transfer to the same line for the remaining balance', function () {
    [$job, $line] = splitJob('SFV-2026-0014', 'Sultan Ibrahim', '+18185551014');

    $first = bankTxn(['amount_cents' => -39900, 'date' => '2026-07-07', 'name' => 'Zelle payment from SULTAN IBRAHIM']);
    test()->matcher->matchOne($first);
    $first->refresh();
    expect($first->matches->first()->job_payment_id)->toBe($line->id);

    $second = bankTxn(['amount_cents' => -39900, 'date' => '2026-07-08', 'name' => 'Zelle payment from SULTAN IBRAHIM ref9284']);
    test()->matcher->matchOne($second);
    $second->refresh();

    expect($second->match_status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and($second->matches->first()->job_payment_id)->toBe($line->id)
        ->and($second->matches->first()->job_id)->toBe($job->id);
});

// ---- Auto-matcher: a coincidentally-equal amount from a DIFFERENT customer must NOT auto-match ----

it('does not auto-match an unrelated customer whose deposit coincidentally equals the remaining balance', function () {
    [, $line] = splitJob('SFV-2026-0014', 'Sultan Ibrahim', '+18185551014');

    $first = bankTxn(['amount_cents' => -39900, 'date' => '2026-07-07', 'name' => 'Zelle payment from SULTAN IBRAHIM']);
    test()->matcher->matchOne($first);
    expect($first->refresh()->matches->first()->job_payment_id)->toBe($line->id);

    // A totally different customer's Zelle, same window, same amount by pure
    // coincidence — must stay unmatched for a human, exactly like today's
    // behavior for any other genuinely ambiguous transaction.
    $unrelated = bankTxn(['amount_cents' => -39900, 'date' => '2026-07-08', 'name' => 'Zelle payment from MARIA GONZALEZ']);
    test()->matcher->matchOne($unrelated);
    $unrelated->refresh();

    expect($unrelated->match_status)->toBe(BankTransaction::STATUS_UNMATCHED)
        ->and($unrelated->matches)->toBeEmpty();
});

// ---- Manual picker: the partially-claimed line is still offered, with its true remaining balance ----

it('still offers a partially-claimed line in the manual picker, labeled with its remaining balance', function () {
    [, $line] = splitJob('SFV-2026-0014', 'Sultan Ibrahim', '+18185551014');

    $first = bankTxn(['amount_cents' => -39900, 'date' => '2026-07-07', 'name' => 'Zelle payment from SULTAN IBRAHIM']);
    test()->matcher->matchOne($first);

    $second = bankTxn(['amount_cents' => -39900, 'date' => '2026-07-08', 'name' => 'Zelle payment from SULTAN IBRAHIM']);

    $options = defaultPaymentLineOptionsFor($second);

    expect($options)->toHaveKey($line->id)
        ->and($options[$line->id])->toContain('$399.00 left to match');
});

// ---- Manual picker: once fully claimed, the line drops out entirely ----

it('stops offering a line once two real transactions have fully claimed it', function () {
    [, $line] = splitJob('SFV-2026-0014', 'Sultan Ibrahim', '+18185551014');

    $first = bankTxn(['amount_cents' => -39900, 'date' => '2026-07-07', 'name' => 'Zelle payment from SULTAN IBRAHIM']);
    test()->matcher->matchOne($first);
    $second = bankTxn(['amount_cents' => -39900, 'date' => '2026-07-08', 'name' => 'Zelle payment from SULTAN IBRAHIM']);
    test()->matcher->matchOne($second);

    $third = bankTxn(['amount_cents' => -39900, 'date' => '2026-07-08', 'name' => 'Zelle payment from SULTAN IBRAHIM']);
    $options = defaultPaymentLineOptionsFor($third);

    expect($options)->not->toHaveKey($line->id);
});

// ---- Manual match modal: over-claiming a line's remaining balance requires an explanation ----

it('requires a mismatch note when a manual match would over-claim a line past its remaining balance', function () {
    [, $line] = splitJob('SFV-2026-0014', 'Sultan Ibrahim', '+18185551014');

    $first = bankTxn(['amount_cents' => -39900, 'date' => '2026-07-07', 'name' => 'Zelle payment from SULTAN IBRAHIM']);
    test()->matcher->matchOne($first);

    // $500 against a $399 remaining balance — over-claiming, needs a note.
    $second = bankTxn(['amount_cents' => -50000, 'date' => '2026-07-08', 'name' => 'Zelle payment from SULTAN IBRAHIM']);

    Livewire\Livewire::actingAs(test()->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('match', $second, data: [
            'kind' => BankMatchKind::JobPayment->value,
            'job_payment_ids' => [$line->id],
        ])
        ->assertHasTableActionErrors(['payment_mismatch_note']);

    Livewire\Livewire::actingAs(test()->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('match', $second, data: [
            'kind' => BankMatchKind::JobPayment->value,
            'job_payment_ids' => [$line->id],
            'payment_mismatch_note' => 'Customer sent $500 by mistake for the remaining $399.',
        ])
        ->assertHasNoTableActionErrors();
});

// ---- Manual match modal: matching the exact remaining balance is a clean match, no note required ----

it('matches the exact remaining balance cleanly, with no mismatch note required', function () {
    [$job, $line] = splitJob('SFV-2026-0014', 'Sultan Ibrahim', '+18185551014');

    $first = bankTxn(['amount_cents' => -39900, 'date' => '2026-07-07', 'name' => 'Zelle payment from SULTAN IBRAHIM']);
    test()->matcher->matchOne($first);

    $second = bankTxn(['amount_cents' => -39900, 'date' => '2026-07-08', 'name' => 'Zelle payment from SULTAN IBRAHIM']);

    Livewire\Livewire::actingAs(test()->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('match', $second, data: [
            'kind' => BankMatchKind::JobPayment->value,
            'job_payment_ids' => [$line->id],
        ])
        ->assertHasNoTableActionErrors();

    $second->refresh();
    expect($second->match_status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($second->matches->first()->job_payment_id)->toBe($line->id)
        ->and($second->matches->first()->job_id)->toBe($job->id)
        ->and($second->matches->first()->mismatch_note)->toBeNull();
});
