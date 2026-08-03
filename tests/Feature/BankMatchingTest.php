<?php

use App\Enums\BankMatchKind;
use App\Enums\IssueType;
use App\Filament\Pages\Dividends;
use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Issue;
use App\Models\Job;
use App\Models\PlaidItem;
use App\Models\TechSettlement;
use App\Services\BankMatcher;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->item = PlaidItem::create([
        'item_id' => 'item-test-1',
        'access_token' => 'access-test',
        'institution_name' => 'Chase',
    ]);

    $this->tech = Employee::create([
        'name' => 'Ramon Reyes', 'email' => 'r@test.local', 'can_login' => false, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['technician'],
    ]);

    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'ledgeradmin@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->admin->assignRole('super_admin');

    $this->matcher = app(BankMatcher::class);
});

/** An eod_payout TechSettlement, with sensible defaults so tests only pass what matters. */
function eodPayout(array $overrides = []): TechSettlement
{
    return TechSettlement::create(array_merge([
        'technician_id' => test()->tech->id,
        'type' => 'eod_payout',
        'for_date' => '2026-07-01',
        'amount_cents' => 20000,
        'direction' => 'paid_to_tech',
        'tech_pay_owed_cents' => 20000,
        'settled_at' => '2026-07-01 20:00:00',
    ], $overrides));
}

function bankTxn(array $overrides = []): BankTransaction
{
    return BankTransaction::create(array_merge([
        'plaid_item_id' => test()->item->id,
        'plaid_transaction_id' => 'txn-'.fake()->unique()->uuid(),
        'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking',
        'amount_cents' => 10000,
        'date' => '2026-07-01',
        'name' => 'Some transaction',
        'pending' => false,
    ], $overrides));
}

/**
 * BankMatcher now matches against job_payments LINES, not a job's summary
 * columns — so every fixture that sets amount_collected_cents/payment_method
 * needs a matching line for the matcher to find. Jobs that explicitly blank
 * those (a parts-only fixture) get no line, same as before.
 */
function paidJob(array $overrides = []): Job
{
    $customer = Customer::firstOrCreate(['phone_e164' => '+18185550000'], ['first_name' => 'Test']);

    $attrs = array_merge([
        'job_number' => 'LA-2026-'.str_pad((string) (Job::count() + 1), 4, '0', STR_PAD_LEFT),
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Alan Fairview',
        'status' => 'completed',
        'amount_collected_cents' => 45000,
        'payment_method' => 'zelle',
        'payment_received_at' => '2026-06-30 18:00:00',
    ], $overrides);

    $job = Job::create($attrs);

    if (! empty($attrs['amount_collected_cents']) && ! empty($attrs['payment_method'])) {
        $job->payments()->create([
            'amount_cents' => $attrs['amount_collected_cents'],
            'method' => $attrs['payment_method'],
            'collected_at' => $attrs['payment_received_at'] ?? now(),
        ]);
    }

    return $job;
}

it('renders the ledger page with tabs for an admin', function () {
    // Regression: the tab/table modifyQueryUsing closures must name their param
    // $query (Filament injects by name) — $q 500'd the page in production.
    $admin = Employee::create([
        'name' => 'Admin', 'email' => 'a@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $admin->assignRole('super_admin');

    paidJob();
    bankTxn(['amount_cents' => -45000, 'name' => 'Zelle payment from ALAN FAIRVIEW']);
    $this->matcher->run();

    Livewire\Livewire::actingAs($admin)
        ->test(ListBankTransactions::class)
        ->assertSuccessful()
        ->assertSee('Needs review')
        ->assertSee('Suggested');
});

it('suggests a customer payment for an incoming deposit with the exact amount', function () {
    $job = paidJob();
    // Plaid sign convention: negative = money IN.
    $txn = bankTxn(['amount_cents' => -45000, 'name' => 'Zelle payment from ALAN FAIRVIEW']);

    $this->matcher->run();

    $txn->refresh();
    expect($txn->match_status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and($txn->matches)->toHaveCount(1)
        ->and($txn->matches->first()->kind)->toBe(BankMatchKind::JobPayment)
        ->and($txn->matches->first()->job_id)->toBe($job->id)
        ->and($txn->matches->first()->source)->toBe('auto');
});

it('suggests a customer payment when the amount is within $5 (fuzzy)', function () {
    $job = paidJob(['amount_collected_cents' => 45000]);
    // $453 deposit vs $450 collected — $3 off, inside the ±$5 tolerance.
    $txn = bankTxn(['amount_cents' => -45300, 'name' => 'Zelle payment from ALAN FAIRVIEW']);

    $this->matcher->run();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and($txn->matches->first()->job_id)->toBe($job->id);
});

/**
 * 2026-07-30 real incident (LegacyPaymentMatcher, but this is the same
 * disambiguate() a single-candidate BankMatcher::findCustomerPayment() call
 * shares): Misti Schwartz's $425.00 payment auto-matched to "AKIRA
 * NAKAMURA" for $427.90 — same amount/date proximity, zero name
 * correspondence — because a single candidate used to skip the name check
 * entirely. The SAME risk exists here: a sole candidate within the fuzzy
 * amount tolerance must still show the customer's name in the description.
 */
it('does not amount-match the sole candidate in the window when its name has zero correspondence to the customer', function () {
    paidJob(['customer_name_snapshot' => 'Misti Schwartz', 'amount_collected_cents' => 42500]);
    // $2.90 off, inside the +/-$5 tolerance — but the description names a
    // completely different, unrelated person.
    $txn = bankTxn(['amount_cents' => -42790, 'name' => 'Zelle payment from AKIRA NAKAMURA']);

    $this->matcher->run();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED)
        ->and($txn->matches)->toBeEmpty();
});

it('does not amount-match a deposit more than $5 off with no name in the description', function () {
    paidJob(['amount_collected_cents' => 45000]);
    // $10 off and the description carries no customer name → nothing to go on.
    $txn = bankTxn(['amount_cents' => -46000, 'name' => 'ONLINE TRANSFER DEPOSIT']);

    $this->matcher->run();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED);
});

it('falls back to a first-name match when the amount does not line up', function () {
    $job = paidJob(['customer_name_snapshot' => 'Alan Fairview', 'amount_collected_cents' => 45000]);
    // Amount is nowhere near, but the first name is in the description.
    $txn = bankTxn(['amount_cents' => -99900, 'name' => 'Zelle payment from ALAN']);

    $this->matcher->run();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and($txn->matches->first()->kind)->toBe(BankMatchKind::JobPayment)
        ->and($txn->matches->first()->job_id)->toBe($job->id);
});

it('disambiguates same-amount payments by the customer name in the description', function () {
    $alan = paidJob(['customer_name_snapshot' => 'Alan Fairview']);
    $maria = paidJob(['customer_name_snapshot' => 'Maria Gomez']);

    $txn = bankTxn(['amount_cents' => -45000, 'name' => 'Zelle payment from MARIA GOMEZ']);

    $this->matcher->run();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and($txn->matches->first()->job_id)->toBe($maria->id);
});

it('leaves ambiguous deposits unmatched', function () {
    paidJob(['customer_name_snapshot' => 'Alan Fairview']);
    paidJob(['customer_name_snapshot' => 'Bob Fairview']); // both hit "finkelstein"

    $txn = bankTxn(['amount_cents' => -45000, 'name' => 'Zelle payment from FAIRVIEW']);

    $this->matcher->run();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED)
        ->and($txn->matches)->toHaveCount(0);
});

it('does not match a cash job to a deposit', function () {
    paidJob(['payment_method' => 'cash']);

    $txn = bankTxn(['amount_cents' => -45000, 'name' => 'ATM cash deposit']);

    $this->matcher->run();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED);
});

it('suggests a tech payout for an outgoing zelle matching the net EOD amount', function () {
    $settlement = TechSettlement::create([
        'technician_id' => $this->tech->id,
        'type' => 'eod_payout',
        'for_date' => '2026-06-30',
        'amount_cents' => 20000, // $300 labor − $100 cash balance = $200 net
        'direction' => 'paid_to_tech',
        'tech_pay_owed_cents' => 30000,
        'settled_at' => '2026-07-01 20:00:00',
    ]);

    $txn = bankTxn(['amount_cents' => 20000, 'date' => '2026-07-01', 'name' => 'Zelle payment to RAMON REYES']);

    $this->matcher->run();

    $txn->refresh();
    expect($txn->match_status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and($txn->matches->first()->kind)->toBe(BankMatchKind::TechPayout)
        ->and($txn->matches->first()->tech_settlement_id)->toBe($settlement->id);
});

// Deliberately NOT Mygrant here — a Mygrant charge is an aggregated weekly
// total across several vendor_documents, so it now routes through the
// dedicated App\Services\Vendors\VendorChargeReconciler FIRST (see
// tests/Feature/MygrantReconciliationTest.php) rather than this generic
// one-job-exact-amount path. Pilkington/IGC/etc. keep this behavior exactly.
it('suggests a parts purchase for a vendor charge matching a job part cost', function () {
    $job = paidJob([
        'part_cost_cents' => 18500,
        'amount_collected_cents' => null,
        'payment_method' => null,
        'payment_received_at' => null,
        'created_at' => '2026-06-25 12:00:00',
    ]);

    $txn = bankTxn(['amount_cents' => 18500, 'name' => 'PILKINGTON NA', 'merchant_name' => 'Pilkington']);

    $this->matcher->run();

    $txn->refresh();
    expect($txn->match_status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and($txn->matches->first()->kind)->toBe(BankMatchKind::PartPurchase)
        ->and($txn->matches->first()->job_id)->toBe($job->id);
});

it('auto-matches deterministic misc like Google Ads without review', function () {
    $txn = bankTxn(['amount_cents' => 34350, 'name' => 'GOOGLE ADS3948572 CCD']);

    $this->matcher->run();

    $txn->refresh();
    expect($txn->match_status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($txn->matches->first()->kind)->toBe(BankMatchKind::Misc)
        ->and($txn->matches->first()->misc_category)->toBe('google_ads');
});

it('auto-matches a Google Ads charge right at the $500 cap without review', function () {
    $txn = bankTxn(['amount_cents' => 50000, 'name' => 'GOOGLE ADS3948572 CCD']);

    $this->matcher->run();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_MATCHED);
});

it('suggests instead of auto-matching a Google Ads charge over the $500 cap', function () {
    $txn = bankTxn(['amount_cents' => 52350, 'name' => 'GOOGLE ADS3948572 CCD']);

    $this->matcher->run();

    $txn->refresh();
    expect($txn->match_status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and($txn->matches->first()->kind)->toBe(BankMatchKind::Misc)
        ->and($txn->matches->first()->misc_category)->toBe('google_ads');
});

it('does not auto-match an uncapped misc category regardless of amount', function () {
    $txn = bankTxn(['amount_cents' => 999999, 'name' => 'MONTHLY SERVICE FEE']);

    $this->matcher->run();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_MATCHED);
});

it('auto-matches an outgoing Zelle to Owner Operated as back office services', function () {
    $txn = bankTxn(['amount_cents' => 150000, 'name' => 'Zelle Payment To Owner Operated Llc 23847562']);

    $this->matcher->run();

    $txn->refresh();
    expect($txn->match_status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($txn->matches->first()->kind)->toBe(BankMatchKind::Misc)
        ->and($txn->matches->first()->misc_category)->toBe('back_office_services');
});

it('does not treat an incoming Zelle from Owner Operated as back office services', function () {
    $txn = bankTxn(['amount_cents' => -150000, 'name' => 'Zelle Payment From Owner Operated Llc 23847562']);

    $this->matcher->run();

    $txn->refresh();
    expect($txn->match_status)->not->toBe(BankTransaction::STATUS_MATCHED)
        ->and($txn->matches)->toBeEmpty();
});

it('never suggests the same job for two deposits', function () {
    paidJob();
    $first = bankTxn(['amount_cents' => -45000, 'name' => 'Zelle from ALAN', 'date' => '2026-07-01']);
    $second = bankTxn(['amount_cents' => -45000, 'name' => 'Zelle from ALAN', 'date' => '2026-07-02']);

    $this->matcher->run();

    $statuses = collect([$first->refresh(), $second->refresh()])->pluck('match_status');
    expect($statuses->filter(fn ($s) => $s === BankTransaction::STATUS_SUGGESTED))->toHaveCount(1);
});

it('skips pending transactions and is idempotent on re-run', function () {
    paidJob();
    $pending = bankTxn(['amount_cents' => -45000, 'name' => 'Zelle from ALAN', 'pending' => true]);

    $this->matcher->run();
    expect($pending->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED);

    $posted = bankTxn(['amount_cents' => -45000, 'name' => 'Zelle from ALAN']);
    $this->matcher->run();
    $this->matcher->run(); // second run must not double-link

    expect($posted->refresh()->matches)->toHaveCount(1);
});

// ---- Tech-payout matching (zelle_payout_name / techFor) ----

it('suggests the exact match to the named tech even when another tech shares a name token and the same amount', function () {
    $this->tech->update(['zelle_payout_name' => 'Jose Aguirre']);
    $other = Employee::create([
        'name' => 'Jose Ramirez', 'email' => 'jose-other@test.local', 'can_login' => false, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['technician'],
    ]);

    $mySettlement = eodPayout(['settled_at' => '2026-07-01 20:00:00']);
    eodPayout(['technician_id' => $other->id, 'settled_at' => '2026-07-01 20:05:00']);

    $txn = bankTxn(['amount_cents' => 20000, 'name' => 'Zelle payment to Jose Aguirre JPM99887766']);

    $this->matcher->run();

    $txn->refresh();
    expect($txn->match_status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and($txn->matches->first()->kind)->toBe(BankMatchKind::TechPayout)
        ->and($txn->matches->first()->tech_settlement_id)->toBe($mySettlement->id)
        ->and($txn->matches->first()->expected_amount_cents)->toBeNull();
});

it('never matches an off-amount payout — tech payouts must be the exact amount', function () {
    $this->tech->update(['zelle_payout_name' => 'Jose Aguirre']);
    // The only payout for this tech is $200; the bank sent $150 — no match.
    eodPayout(['amount_cents' => 20000, 'settled_at' => '2026-07-01 20:00:00']);
    $txn = bankTxn(['amount_cents' => 15000, 'date' => '2026-07-01', 'name' => 'Zelle payment to Jose Aguirre']);

    $this->matcher->run();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED);
});

it('never matches a payout settled on a different day, even for the exact amount', function () {
    $this->tech->update(['zelle_payout_name' => 'Jose Aguirre']);
    // Exact amount, but settled two days before the bank transaction posted.
    eodPayout(['amount_cents' => 20000, 'settled_at' => '2026-06-29 20:00:00']);
    $txn = bankTxn(['amount_cents' => 20000, 'date' => '2026-07-01', 'name' => 'Zelle payment to Jose Aguirre']);

    $this->matcher->run();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED);
});

it('matches a payout to a Zelle by its initiation date, not the later posted date', function () {
    $this->tech->update(['zelle_payout_name' => 'Jose Aguirre']);
    // Payout stamped Jul 10; the Zelle only posts at the bank Jul 13. Keying on
    // authorized_date (the Jul 10 initiation) is what lets them line up.
    $settlement = eodPayout(['amount_cents' => 20000, 'settled_at' => '2026-07-10 20:00:00']);
    $txn = bankTxn([
        'amount_cents' => 20000,
        'date' => '2026-07-13',
        'authorized_date' => '2026-07-10',
        'name' => 'Zelle payment to Jose Aguirre',
    ]);

    $this->matcher->run();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and($txn->matches->first()->tech_settlement_id)->toBe($settlement->id);
});

it('rejects a payout on the posted day when the Zelle was initiated on a different day', function () {
    $this->tech->update(['zelle_payout_name' => 'Jose Aguirre']);
    // Zelle initiated Jul 10, posts Jul 13. A payout settled on the POSTED day
    // (Jul 13) must NOT match — only the initiation day (Jul 10) is allowed.
    eodPayout(['amount_cents' => 20000, 'settled_at' => '2026-07-13 20:00:00']);
    $txn = bankTxn([
        'amount_cents' => 20000,
        'date' => '2026-07-13',
        'authorized_date' => '2026-07-10',
        'name' => 'Zelle payment to Jose Aguirre',
    ]);

    $this->matcher->run();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED);
});

it('falls back to the employee name when zelle_payout_name is unset (pre-existing behavior stays green)', function () {
    // $this->tech ('Ramon Reyes') has no zelle_payout_name set.
    $settlement = eodPayout(['amount_cents' => 20000, 'settled_at' => '2026-07-01 20:00:00']);
    $txn = bankTxn(['amount_cents' => 20000, 'name' => 'Zelle payment to RAMON REYES']);

    $this->matcher->run();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and($txn->matches->first()->tech_settlement_id)->toBe($settlement->id);
});

it('does not match techFor when only one name token overlaps', function () {
    $this->tech->update(['zelle_payout_name' => 'Jose Aguirre']);
    $txn = bankTxn(['amount_cents' => 20000, 'name' => 'Zelle payment to Jose Martinez']);

    expect($this->matcher->techFor($txn))->toBeNull();
});

it('techFor ignores money-in transactions and parts-vendor descriptions', function () {
    $this->tech->update(['zelle_payout_name' => 'Jose Aguirre']);

    $moneyIn = bankTxn(['amount_cents' => -20000, 'name' => 'Zelle payment to Jose Aguirre']);
    expect($this->matcher->techFor($moneyIn))->toBeNull();

    $vendor = bankTxn(['amount_cents' => 20000, 'name' => 'Zelle payment to Jose Aguirre', 'merchant_name' => 'Mygrant Glass']);
    expect($this->matcher->techFor($vendor))->toBeNull();
});

// ---- Dividend payouts (outgoing zelle to an owner) ----

it('auto-matches an outgoing zelle to an owner as a dividend without review', function () {
    $txn = bankTxn(['amount_cents' => 500000, 'name' => 'Zelle payment to PAT OWNER JPM123']);

    $this->matcher->run();

    $txn->refresh();
    expect($txn->match_status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($txn->matches->first()->kind)->toBe(BankMatchKind::Dividend)
        ->and($txn->matches->first()->misc_category)->toBe('pat_owner')
        ->and($txn->matches->first()->source)->toBe('auto')
        ->and($txn->matches->first()->dividendRecipientName())->toBe('Pat Owner');
});

it('classifies the second owner as a dividend too', function () {
    $txn = bankTxn(['amount_cents' => 250000, 'name' => 'Zelle payment to SAM PARTNER']);

    $this->matcher->run();

    expect($txn->refresh()->matches->first()->misc_category)->toBe('sam_partner');
});

it('does not treat an incoming zelle from a customer named like an owner as a dividend', function () {
    // Money IN (negative) is never a dividend — dividends only leave the account.
    $txn = bankTxn(['amount_cents' => -500000, 'name' => 'Zelle payment from PAT OWNER']);

    $this->matcher->run();

    $match = $txn->refresh()->matches->first();
    expect(optional($match)->kind)->not->toBe(BankMatchKind::Dividend);
});

it('does not classify a partial-name owner match as a dividend', function () {
    // Only "alex" overlaps — both name tokens are required.
    $txn = bankTxn(['amount_cents' => 500000, 'name' => 'Zelle payment to ALEX JOHNSON']);

    $this->matcher->run();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED);
});

it('prefers a dividend over a tech payout for an owner zelle even at a matching amount', function () {
    // A same-day exact-amount payout exists, but the description names an owner,
    // so it must classify as a dividend, not steal the tech's payout.
    eodPayout(['amount_cents' => 500000, 'settled_at' => '2026-07-01 20:00:00']);
    $txn = bankTxn(['amount_cents' => 500000, 'date' => '2026-07-01', 'name' => 'Zelle payment to PAT OWNER']);

    $this->matcher->run();

    expect($txn->refresh()->matches->first()->kind)->toBe(BankMatchKind::Dividend);
});

it('lets an admin manually match a transaction as a dividend to an owner', function () {
    $txn = bankTxn(['amount_cents' => 300000, 'name' => 'OUTGOING WIRE']);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('match', $txn, data: [
            'kind' => BankMatchKind::Dividend->value,
            'dividend_recipient' => 'sam_partner',
        ])
        ->assertHasNoTableActionErrors();

    $txn->refresh();
    expect($txn->match_status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($txn->matches->first()->kind)->toBe(BankMatchKind::Dividend)
        ->and($txn->matches->first()->misc_category)->toBe('sam_partner');
});

it('lists dividend payouts on the Dividends finance page', function () {
    bankTxn(['amount_cents' => 500000, 'name' => 'Zelle payment to PAT OWNER']);
    bankTxn(['amount_cents' => -45000, 'name' => 'Zelle payment from ALAN']); // not a dividend
    $this->matcher->run();

    Livewire\Livewire::actingAs($this->admin)
        ->test(Dividends::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(BankTransaction::whereHas('matches', fn ($q) => $q->where('kind', 'dividend'))->get())
        ->assertSee('Pat Owner');
});

// ---- Ledger UI: lock + mismatch explanation ----

it('locks the manual match kind to tech payout for a zelle-to-tech transaction', function () {
    $this->tech->update(['zelle_payout_name' => 'Jose Aguirre']);
    $txn = bankTxn(['amount_cents' => 20000, 'name' => 'Zelle payment to Jose Aguirre']);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->mountTableAction('match', $txn)
        ->assertTableActionDataSet(['kind' => BankMatchKind::TechPayout->value]);

    // The kind is genuinely locked, not just defaulted — the identified tech
    // is the only option the Select offers.
    expect(app(BankMatcher::class)->techFor($txn)->id)->toBe($this->tech->id);
});

it('the manual picker requires a mismatch note for an off-amount same-day payout, and accepts one with it', function () {
    $this->tech->update(['zelle_payout_name' => 'Jose Aguirre']);
    // Same day, off amount — now a VALID option (unlike auto-suggest), but the
    // human must explain the difference.
    $offAmount = eodPayout(['amount_cents' => 15000, 'settled_at' => '2026-07-01 20:00:00']);
    $txn = bankTxn(['amount_cents' => 20000, 'date' => '2026-07-01', 'name' => 'Zelle payment to Jose Aguirre']);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('match', $txn, data: [
            'kind' => BankMatchKind::TechPayout->value,
            'tech_settlement_id' => $offAmount->id,
        ])
        ->assertHasTableActionErrors(['payout_mismatch_note']);

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('match', $txn, data: [
            'kind' => BankMatchKind::TechPayout->value,
            'tech_settlement_id' => $offAmount->id,
            'payout_mismatch_note' => 'Tech had a $50 advance deducted.',
        ])
        ->assertHasNoTableActionErrors();

    $txn->refresh();
    expect($txn->match_status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($txn->matches->first()->tech_settlement_id)->toBe($offAmount->id)
        ->and($txn->matches->first()->expected_amount_cents)->toBe(15000)
        ->and($txn->matches->first()->mismatch_note)->toBe('Tech had a $50 advance deducted.')
        ->and(Issue::query()->open()->where('type', IssueType::TransactionMismatch->value)->count())->toBe(1);
});

it('the manual picker rejects a payout settled on a different day', function () {
    $this->tech->update(['zelle_payout_name' => 'Jose Aguirre']);
    $offDay = eodPayout(['amount_cents' => 20000, 'settled_at' => '2026-06-29 20:00:00']);
    $txn = bankTxn(['amount_cents' => 20000, 'date' => '2026-07-01', 'name' => 'Zelle payment to Jose Aguirre']);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('match', $txn, data: [
            'kind' => BankMatchKind::TechPayout->value,
            'tech_settlement_id' => $offDay->id,
        ])
        ->assertHasTableActionErrors(['tech_settlement_id']);
});

it('manual match to a same-day, exact-amount payout succeeds with no mismatch issue', function () {
    $this->tech->update(['zelle_payout_name' => 'Jose Aguirre']);
    $settlement = eodPayout(['amount_cents' => 20000, 'settled_at' => '2026-07-01 20:00:00']);
    $txn = bankTxn(['amount_cents' => 20000, 'date' => '2026-07-01', 'name' => 'Zelle payment to Jose Aguirre']);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('match', $txn, data: [
            'kind' => BankMatchKind::TechPayout->value,
            'tech_settlement_id' => $settlement->id,
        ])
        ->assertHasNoTableActionErrors();

    $txn->refresh();
    expect($txn->match_status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($txn->matches->first()->kind)->toBe(BankMatchKind::TechPayout)
        ->and($txn->matches->first()->tech_settlement_id)->toBe($settlement->id)
        ->and($txn->matches->first()->expected_amount_cents)->toBeNull()
        ->and(Issue::query()->count())->toBe(0);
});

it('confirming an exact-amount suggested tech payout stays one-click with no issue', function () {
    eodPayout(['amount_cents' => 20000, 'settled_at' => '2026-07-01 20:00:00']);
    $txn = bankTxn(['amount_cents' => 20000, 'date' => '2026-07-01', 'name' => 'Zelle payment to RAMON REYES']);

    $this->matcher->run();
    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_SUGGESTED);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('confirm', $txn, data: [])
        ->assertHasNoTableActionErrors();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and(Issue::query()->count())->toBe(0);
});

it('unmatch deletes an open mismatch issue on the transaction but leaves a cleared one alone', function () {
    eodPayout(['amount_cents' => 20000, 'settled_at' => '2026-07-01 20:00:00']);
    $txn = bankTxn(['amount_cents' => 20000, 'date' => '2026-07-01', 'name' => 'Zelle payment to RAMON REYES']);

    // Match it, then hang an open TransactionMismatch issue on it (as could be
    // raised elsewhere — the ledger clears any such open issue on unmatch).
    $this->matcher->run();
    $openIssue = Issue::raise(IssueType::TransactionMismatch, $txn->refresh(), ['note' => 'x'], $this->admin->id);

    // The txn is "suggested"/"matched", which drops out of the default "Needs
    // review" tab once matched — switch to "All" so the action can resolve it.
    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->set('activeTab', 'all')
        ->callTableAction('unmatch', $txn)
        ->assertHasNoTableActionErrors();

    expect(Issue::find($openIssue->id))->toBeNull();

    // A CLEARED issue must survive a later unmatch.
    $this->matcher->run();
    $issue2 = Issue::raise(IssueType::TransactionMismatch, $txn->refresh(), [], $this->admin->id);
    $issue2->clear($this->admin->id, 'reviewed');

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->set('activeTab', 'all')
        ->callTableAction('unmatch', $txn)
        ->assertHasNoTableActionErrors();

    $issue2->refresh();
    expect($issue2->status)->toBe('cleared');
});
