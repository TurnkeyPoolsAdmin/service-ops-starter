<?php

use App\Enums\BankMatchKind;
use App\Enums\IssueType;
use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Filament\Resources\BankTransactions\Tables\BankTransactionsTable;
use App\Models\BankTransaction;
use App\Models\BankTransactionMatch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Issue;
use App\Models\Job;
use App\Models\PlaidItem;
use App\Models\Warehouse;
use App\Services\BankMatcher;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coverage for the 2026-07-23 line-aware bank-matching rework: the tech-payout
 * picker offering any same-day payout (not just exact-amount), the mismatch
 * note → Issue flow, LINE-aware customer-payment matching (including the old
 * Xpro-then-Zelle gap), net-of-tech-paid parts matching, the job_payment_id
 * backfill migration, and the parts warehouse preselect. Reuses the bankTxn() /
 * paidJob() / eodPayout() helpers declared in BankMatchingTest.php — Pest
 * registers every test file's top-level functions globally before any test
 * runs, so they're available here without redeclaring them.
 */
beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->item = PlaidItem::create([
        'item_id' => 'item-lines-test-1',
        'access_token' => 'access-test',
        'institution_name' => 'Chase',
    ]);

    $this->tech = Employee::create([
        'name' => 'Ramon Reyes', 'email' => 'r-lines@test.local', 'can_login' => false, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['technician'],
    ]);

    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'ledgeradmin-lines@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->admin->assignRole('super_admin');

    $this->matcher = app(BankMatcher::class);
});

// ---- 1. payoutOptions: same-day off-amount is offered; undone/zero/linked are excluded ----

it('excludes undone, zero-amount, and already-linked payouts from the manual tech-payout picker', function () {
    $this->tech->update(['zelle_payout_name' => 'Jose Aguirre']);

    $undone = eodPayout([
        'amount_cents' => 15000, 'settled_at' => '2026-07-01 20:00:00',
        'undone_at' => now(), 'undone_by' => $this->admin->id,
    ]);
    $zero = eodPayout(['amount_cents' => 0, 'settled_at' => '2026-07-01 21:00:00']);
    $linkedElsewhere = eodPayout(['amount_cents' => 15000, 'settled_at' => '2026-07-01 22:00:00']);

    $priorTxn = bankTxn(['amount_cents' => 15000, 'date' => '2026-07-01', 'name' => 'Zelle payment to Jose Aguirre']);
    BankTransactionMatch::create([
        'bank_transaction_id' => $priorTxn->id,
        'kind' => BankMatchKind::TechPayout->value,
        'tech_settlement_id' => $linkedElsewhere->id,
        'source' => 'manual',
    ]);

    foreach ([$undone, $zero, $linkedElsewhere] as $settlement) {
        $txn = bankTxn(['amount_cents' => 20000, 'date' => '2026-07-01', 'name' => 'Zelle payment to Jose Aguirre']);

        Livewire\Livewire::actingAs($this->admin)
            ->test(ListBankTransactions::class)
            ->callTableAction('match', $txn, data: [
                'kind' => BankMatchKind::TechPayout->value,
                'tech_settlement_id' => $settlement->id,
                'payout_mismatch_note' => 'explanation',
            ])
            ->assertHasTableActionErrors(['tech_settlement_id']);
    }
});

// ---- 2. Mismatch note is stored + raises an Issue; unmatch clears it ----

it('unmatch clears a mismatch Issue raised by a manual off-amount tech-payout match', function () {
    $this->tech->update(['zelle_payout_name' => 'Jose Aguirre']);
    $offAmount = eodPayout(['amount_cents' => 15000, 'settled_at' => '2026-07-01 20:00:00']);
    $txn = bankTxn(['amount_cents' => 20000, 'date' => '2026-07-01', 'name' => 'Zelle payment to Jose Aguirre']);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('match', $txn, data: [
            'kind' => BankMatchKind::TechPayout->value,
            'tech_settlement_id' => $offAmount->id,
            'payout_mismatch_note' => 'Tech had a $50 advance deducted.',
        ])
        ->assertHasNoTableActionErrors();

    expect(Issue::query()->open()->where('type', IssueType::TransactionMismatch->value)->count())->toBe(1);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->set('activeTab', 'all')
        ->callTableAction('unmatch', $txn)
        ->assertHasNoTableActionErrors();

    expect(Issue::query()->open()->where('type', IssueType::TransactionMismatch->value)->count())->toBe(0);
});

// ---- 3. Auto-matcher finds the correct payment LINE on a partially-paid job ----

it('auto-matches each deposit to its own payment LINE on a partially-paid job', function () {
    $customer = Customer::firstOrCreate(['phone_e164' => '+18185559001'], ['first_name' => 'Deposit']);
    $job = Job::create([
        'job_number' => 'LA-2026-9001',
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Deposit Customer',
        'status' => 'completed',
        'quote_amount_cents' => 90000,
    ]);

    $deposit = $job->payments()->create(['amount_cents' => 30000, 'method' => 'zelle', 'collected_at' => '2026-07-01 10:00:00']);
    $balance = $job->payments()->create(['amount_cents' => 60000, 'method' => 'venmo', 'collected_at' => '2026-07-05 10:00:00']);

    $depositTxn = bankTxn(['amount_cents' => -30000, 'date' => '2026-07-01', 'name' => 'Zelle payment from DEPOSIT CUSTOMER']);
    $balanceTxn = bankTxn(['amount_cents' => -60000, 'date' => '2026-07-05', 'name' => 'Venmo payment from DEPOSIT CUSTOMER']);

    $this->matcher->run();

    $depositTxn->refresh();
    $balanceTxn->refresh();

    expect($depositTxn->matches->first()->job_payment_id)->toBe($deposit->id)
        ->and($depositTxn->matches->first()->job_id)->toBe($job->id)
        ->and($balanceTxn->matches->first()->job_payment_id)->toBe($balance->id)
        ->and($balanceTxn->matches->first()->job_id)->toBe($job->id);
});

// ---- 4. The old Xpro-then-Zelle gap is closed ----

it('auto-matches the Zelle line on a job that was part-paid by Xpro then finished by Zelle', function () {
    $customer = Customer::firstOrCreate(['phone_e164' => '+18185559002'], ['first_name' => 'Xpro']);
    $job = Job::create([
        'job_number' => 'LA-2026-9002',
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Xpro Customer',
        'status' => 'completed',
        'quote_amount_cents' => 72500,
    ]);

    // Xpro collected half on their own terminal (never hits Chase); the
    // customer paid the rest directly by Zelle.
    $job->payments()->create(['amount_cents' => 36250, 'method' => 'swiftswipe_xpro', 'collected_at' => '2026-07-01 10:00:00']);
    $zelleLine = $job->payments()->create(['amount_cents' => 36250, 'method' => 'zelle', 'collected_at' => '2026-07-03 10:00:00']);

    $txn = bankTxn(['amount_cents' => -36250, 'date' => '2026-07-03', 'name' => 'Zelle payment from XPRO CUSTOMER']);

    $this->matcher->run();

    $txn->refresh();
    expect($txn->match_status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and($txn->matches->first()->job_payment_id)->toBe($zelleLine->id)
        ->and($txn->matches->first()->job_id)->toBe($job->id);
});

// ---- 5. Parts auto-match keys on the NET (part minus tech-paid) amount ----
// Deliberately NOT Mygrant here — a Mygrant charge now routes through the
// dedicated document-level App\Services\Vendors\VendorChargeReconciler FIRST
// (see tests/Feature/MygrantReconciliationTest.php) rather than this generic
// one-job-net-amount path. Pilkington/IGC/etc. keep this behavior exactly.

it('auto-matches a parts charge on the net amount when the tech fronted part of the cost', function () {
    $job = paidJob([
        'part_cost_cents' => 25000,
        'tech_parts_cost_cents' => 10000,
        'amount_collected_cents' => null,
        'payment_method' => null,
        'payment_received_at' => null,
        'created_at' => '2026-06-25 12:00:00',
    ]);

    // The vendor charge is for the NET $150 (the $100 the tech fronted is
    // reimbursed through tech pay, never through Chase), not the gross $250.
    $txn = bankTxn(['amount_cents' => 15000, 'name' => 'PILKINGTON NA', 'merchant_name' => 'Pilkington']);

    $this->matcher->run();

    $txn->refresh();
    expect($txn->match_status)->toBe(BankTransaction::STATUS_SUGGESTED)
        ->and($txn->matches->first()->kind)->toBe(BankMatchKind::PartPurchase)
        ->and($txn->matches->first()->job_id)->toBe($job->id);
});

it('does not match a parts charge on the gross amount once tech-paid cost is netted out', function () {
    paidJob([
        'part_cost_cents' => 25000,
        'tech_parts_cost_cents' => 10000,
        'amount_collected_cents' => null,
        'payment_method' => null,
        'payment_received_at' => null,
        'created_at' => '2026-06-25 12:00:00',
    ]);

    // The gross $250 no longer matches anything — only the net $150 does.
    $txn = bankTxn(['amount_cents' => 25000, 'name' => 'PILKINGTON NA', 'merchant_name' => 'Pilkington']);

    $this->matcher->run();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED);
});

// ---- 6. Backfill migration: single-line gains job_payment_id, multi-line stays null ----

it('backfills job_payment_id onto a single-line job match, and leaves a multi-line job match null', function () {
    // Simulate the pre-migration schema (this migration already ran once, with
    // no data, when the test suite bootstrapped its schema).
    Schema::table('bank_transaction_matches', function (Blueprint $table) {
        $table->dropConstrainedForeignId('job_payment_id');
    });

    $single = paidJob(['customer_name_snapshot' => 'Single Line']);
    $singleTxn = bankTxn(['amount_cents' => -45000, 'name' => 'Zelle from SINGLE']);
    DB::table('bank_transaction_matches')->insert([
        'bank_transaction_id' => $singleTxn->id,
        'kind' => 'job_payment',
        'job_id' => $single->id,
        'source' => 'auto',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $multi = paidJob(['customer_name_snapshot' => 'Multi Line', 'amount_collected_cents' => 30000]);
    $multi->payments()->create(['amount_cents' => 20000, 'method' => 'venmo', 'collected_at' => now()]);
    $multiTxn = bankTxn(['amount_cents' => -30000, 'name' => 'Zelle from MULTI']);
    DB::table('bank_transaction_matches')->insert([
        'bank_transaction_id' => $multiTxn->id,
        'kind' => 'job_payment',
        'job_id' => $multi->id,
        'source' => 'auto',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration = require base_path('database/migrations/2026_07_23_120000_add_job_payment_id_to_bank_transaction_matches.php');
    $migration->up();

    $singleLineId = $single->payments()->first()->id;
    $singleMatchRow = DB::table('bank_transaction_matches')->where('bank_transaction_id', $singleTxn->id)->first();
    $multiMatchRow = DB::table('bank_transaction_matches')->where('bank_transaction_id', $multiTxn->id)->first();

    expect((int) $singleMatchRow->job_payment_id)->toBe($singleLineId)
        ->and($multiMatchRow->job_payment_id)->toBeNull();
});

// ---- 7. Warehouse preselect from the transaction description ----

it('preselects the warehouse whose name is found in the transaction description', function () {
    $mygrant = Warehouse::create(['name' => 'Mygrant', 'active' => true]);
    $txn = bankTxn(['amount_cents' => 15000, 'name' => 'MYGRANT AUTO GLASS']);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->mountTableAction('match', $txn)
        ->assertTableActionDataSet(['warehouse' => (string) $mygrant->id]);
});

it('preselects the warehouse via a configured vendor keyword that fuzzy-maps to exactly one warehouse', function () {
    // The configured keyword is "safelite wholesale" — our warehouse is just
    // "Safelite", so the match has to work both directions (substring).
    $safelite = Warehouse::create(['name' => 'Safelite', 'active' => true]);
    $txn = bankTxn(['amount_cents' => 15000, 'name' => 'SAFELITE WHOLESALE #4471']);

    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->mountTableAction('match', $txn)
        ->assertTableActionDataSet(['warehouse' => (string) $safelite->id]);
});

// ---- 8. 2026-07-31: a settlement/job split across TWO bank transactions stays
// offerable for the REMAINING balance instead of vanishing after the first
// partial match — same sum-aware treatment payment lines already had.

it('a tech payout split across two Zelles stays offerable for the remaining balance, then excludes once fully claimed', function () {
    $this->tech->update(['zelle_payout_name' => 'Jose Aguirre']);
    $settlement = eodPayout(['amount_cents' => 37000, 'settled_at' => '2026-07-01 20:00:00']);

    $first = bankTxn(['amount_cents' => 19000, 'date' => '2026-07-01', 'name' => 'Zelle payment to Jose Aguirre']);
    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('match', $first, data: [
            'kind' => BankMatchKind::TechPayout->value,
            'tech_settlement_id' => $settlement->id,
            'payout_mismatch_note' => 'First of two Zelles for this payout.',
        ])
        ->assertHasNoTableActionErrors();

    // The bug: after the first partial match, the settlement used to vanish
    // from the picker entirely ("No options available") even though only
    // half its value was accounted for.
    $second = bankTxn(['amount_cents' => 18000, 'date' => '2026-07-01', 'name' => 'Zelle payment to Jose Aguirre']);
    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('match', $second, data: [
            'kind' => BankMatchKind::TechPayout->value,
            'tech_settlement_id' => $settlement->id,
            'payout_mismatch_note' => 'Second of two Zelles for this payout.',
        ])
        ->assertHasNoTableActionErrors();

    expect(BankTransactionMatch::where('tech_settlement_id', $settlement->id)->count())->toBe(2);

    // Now fully claimed (19000 + 18000 = 37000) - a third attempt must be
    // excluded, exactly like the pre-existing "already-linked" test above.
    $third = bankTxn(['amount_cents' => 5000, 'date' => '2026-07-01', 'name' => 'Zelle payment to Jose Aguirre']);
    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('match', $third, data: [
            'kind' => BankMatchKind::TechPayout->value,
            'tech_settlement_id' => $settlement->id,
            'payout_mismatch_note' => 'Should not be accepted.',
        ])
        ->assertHasTableActionErrors(['tech_settlement_id']);
});

it('a job\'s part cost split across two vendor charges stays offerable for the remaining balance, then excludes once fully claimed', function () {
    $job = paidJob(['job_number' => 'LA-2026-0555', 'part_cost_cents' => 25000, 'amount_collected_cents' => null, 'payment_method' => null, 'payment_received_at' => null]);

    $first = bankTxn(['amount_cents' => 15000, 'date' => '2026-07-01', 'name' => 'MYGRANT GLASS CO #018']);
    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('match', $first, data: [
            'kind' => BankMatchKind::PartPurchase->value,
            'job_ids' => [$job->id],
            'parts_mismatch_note' => 'First of two charges for this part.',
        ])
        ->assertHasNoTableActionErrors();

    // The same bug, for parts: after the first partial match, the job used
    // to vanish from the "Job(s) the parts were for" picker entirely.
    $second = bankTxn(['amount_cents' => 10000, 'date' => '2026-07-01', 'name' => 'MYGRANT GLASS CO #018']);
    Livewire\Livewire::actingAs($this->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('match', $second, data: [
            'kind' => BankMatchKind::PartPurchase->value,
            'job_ids' => [$job->id],
            'parts_mismatch_note' => 'Second of two charges for this part.',
        ])
        ->assertHasNoTableActionErrors();

    expect(BankTransactionMatch::where('job_id', $job->id)->where('kind', BankMatchKind::PartPurchase->value)->count())->toBe(2);

    // Fully claimed (15000 + 10000 = 25000) - checked directly against the
    // picker's own option builder, not via a Livewire form-validation error:
    // unlike tech_settlement_id (a single Select), job_ids is ->multiple()
    // and Filament doesn't enforce "submitted values must be in options()"
    // for a multi-select through a raw data: array in a test submission, so
    // that route can't actually prove the exclusion here. The exclusion
    // itself is what fullyClaimedPartJobIds()/defaultPartJobOptions() decide.
    $third = bankTxn(['amount_cents' => 5000, 'date' => '2026-07-01', 'name' => 'MYGRANT GLASS CO #018']);
    $ref = new ReflectionMethod(BankTransactionsTable::class, 'defaultPartJobOptions');
    $ref->setAccessible(true);
    $options = $ref->invoke(null, null, $third, []);

    expect($options)->not->toHaveKey($job->id);
});
