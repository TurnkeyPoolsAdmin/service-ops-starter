<?php

use App\Enums\IssueType;
use App\Filament\Pages\Issues;
use App\Models\BankTransaction;
use App\Models\BankTransactionMatch;
use App\Models\Employee;
use App\Models\Issue;
use App\Models\PlaidItem;
use App\Models\Setting;
use App\Models\StripePayout;
use App\Models\StripePayoutLine;
use App\Services\Stripe\StripeClient;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Covers the "next slice" of the Stripe payout pipeline: stripe:resolve-payouts
 * (retry + exhaustion escalation), stripe:backfill-payouts (idempotent
 * historical catch-up), and stripe:match-payouts / StripePayoutReconciler
 * (linking a resolved payout to its actual Chase deposit). StripeClient is
 * faked throughout — no live Stripe access in this environment.
 */
beforeEach(function () {
    // A fixed weekday morning: Telegram posts nothing on a Sunday
    // (App\Support\AlertWindow), so the escalation test must pin the day.
    Carbon::setTestNow(Carbon::parse('2026-08-03 09:00', 'America/Los_Angeles')); // Monday

    test()->seed(RoleSeeder::class);

    test()->admin = Employee::create([
        'name' => 'Admin', 'email' => 'stripe-ops-admin@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    test()->admin->assignRole('super_admin');

    test()->item = PlaidItem::create([
        'item_id' => 'item-stripe-ops', 'access_token' => 'access-test', 'institution_name' => 'Chase',
    ]);

    config(['glass.stripe.payout_resolution_max_attempts' => 3]);
});

afterEach(fn () => Carbon::setTestNow());

function fakeStripeClientOps(?array $transactions = null, ?Throwable $throws = null, ?array $payouts = null): void
{
    $mock = Mockery::mock(StripeClient::class)->makePartial();

    if ($transactions !== null || $throws !== null) {
        $expectation = $mock->shouldReceive('listPayoutBalanceTransactions');
        $throws !== null ? $expectation->andThrow($throws) : $expectation->andReturn($transactions ?? []);
    }

    if ($payouts !== null) {
        $mock->shouldReceive('listAllPayouts')->andReturn($payouts);
    }

    app()->instance(StripeClient::class, $mock);
}

function opsBankTxn(array $overrides = []): BankTransaction
{
    return BankTransaction::create(array_merge([
        'plaid_item_id' => test()->item->id,
        'plaid_transaction_id' => 'txn-'.fake()->unique()->uuid(),
        'plaid_account_id' => 'acc-1',
        'account_name' => 'Chase Total Checking',
        'amount_cents' => -49976, // deposit — Plaid-signed, negative = money in
        'date' => '2026-07-20',
        'name' => 'STRIPE TRANSFER',
        'pending' => false,
        'match_status' => BankTransaction::STATUS_UNMATCHED,
    ], $overrides));
}

// ---- stripe:resolve-payouts ----

it('resolves a payout that previously failed', function () {
    $payout = StripePayout::create([
        'stripe_payout_id' => 'po_retry', 'amount_cents' => 49976, 'currency' => 'usd',
        'arrival_date' => '2026-07-20', 'status' => 'paid',
        'resolution_status' => StripePayout::STATUS_RESOLUTION_FAILED, 'resolution_attempts' => 1,
        'resolution_error' => 'earlier network blip',
    ]);

    fakeStripeClientOps(transactions: [[
        'id' => 'txn_retry', 'type' => 'charge', 'amount' => 51500, 'fee' => 1524, 'net' => 49976,
        'source' => ['id' => 'ch_retry', 'payment_intent' => ['id' => 'pi_none']],
    ]]);

    test()->artisan('stripe:resolve-payouts')->assertSuccessful();

    // pi_none matches no job_payments row -> partial, not resolved. Either
    // way it's no longer resolution_failed — that's what this test asserts.
    expect($payout->refresh()->resolution_status)->toBe(StripePayout::STATUS_PARTIAL);
});

it('leaves a payout retrying when it fails again but is still under the attempt cap', function () {
    Setting::put('telegram_bot_token', 'tok', sensitive: true);
    Setting::put('telegram_chat_id', '-100');
    Http::fake();

    $payout = StripePayout::create([
        'stripe_payout_id' => 'po_fail', 'amount_cents' => 1000, 'currency' => 'usd',
        'arrival_date' => '2026-07-20', 'status' => 'paid',
        'resolution_status' => StripePayout::STATUS_RESOLUTION_FAILED, 'resolution_attempts' => 1,
    ]);

    fakeStripeClientOps(throws: new RuntimeException('still down'));

    test()->artisan('stripe:resolve-payouts')->assertSuccessful();

    $payout->refresh();
    expect($payout->resolution_status)->toBe(StripePayout::STATUS_RESOLUTION_FAILED)
        ->and($payout->resolution_attempts)->toBe(2);

    Http::assertNothingSent();
    expect(Issue::where('type', IssueType::StripePayoutResolutionExhausted->value)->count())->toBe(0);
});

it('escalates to Telegram and the Issues queue once the retry budget is exhausted, and never re-escalates', function () {
    Setting::put('telegram_bot_token', 'tok', sensitive: true);
    Setting::put('telegram_chat_id', '-100');
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

    $payout = StripePayout::create([
        'stripe_payout_id' => 'po_exhaust', 'amount_cents' => 5000, 'currency' => 'usd',
        'arrival_date' => '2026-07-20', 'status' => 'paid',
        'resolution_status' => StripePayout::STATUS_RESOLUTION_FAILED, 'resolution_attempts' => 2, // one more failure hits the configured cap of 3
    ]);

    fakeStripeClientOps(throws: new RuntimeException('Stripe is down'));

    test()->artisan('stripe:resolve-payouts')->assertSuccessful();

    $payout->refresh();
    expect($payout->resolution_status)->toBe(StripePayout::STATUS_RESOLUTION_EXHAUSTED)
        ->and($payout->resolution_attempts)->toBe(3);

    Http::assertSent(fn ($req) => str_contains($req['text'], 'po_exhaust') && str_contains($req['text'], 'resolution failed'));

    expect(Issue::where('type', IssueType::StripePayoutResolutionExhausted->value)
        ->where('subject_id', $payout->id)->count())->toBe(1);

    // Rerunning doesn't re-escalate — an exhausted payout (attempts >= max) is
    // excluded from the retry candidate set entirely.
    Http::fake();
    test()->artisan('stripe:resolve-payouts')->assertSuccessful();
    Http::assertNothingSent();
});

it('renders the new Stripe subview on the Issues page without error', function () {
    $payout = StripePayout::create([
        'stripe_payout_id' => 'po_view', 'amount_cents' => 1234, 'currency' => 'usd',
        'arrival_date' => '2026-07-20', 'status' => 'paid',
        'resolution_status' => StripePayout::STATUS_RESOLUTION_EXHAUSTED, 'resolution_attempts' => 3,
        'resolution_error' => 'boom',
    ]);
    Issue::raise(IssueType::StripePayoutResolutionExhausted, $payout, [
        'stripe_payout_id' => $payout->stripe_payout_id, 'amount_cents' => $payout->amount_cents,
        'resolution_attempts' => $payout->resolution_attempts, 'resolution_error' => $payout->resolution_error,
    ]);

    Livewire\Livewire::actingAs(test()->admin)
        ->test(Issues::class, ['subview' => 'stripe_payout'])
        ->assertSuccessful();
});

// ---- stripe:backfill-payouts ----

it('backfills historical payouts and never reprocesses an already-resolved one on rerun', function () {
    Setting::put('stripe_secret_key', 'sk_test_x', sensitive: true);

    fakeStripeClientOps(
        transactions: [[
            'id' => 'txn_bf', 'type' => 'charge', 'amount' => 1000, 'fee' => 59, 'net' => 941,
            'source' => ['id' => 'ch_bf', 'payment_intent' => ['id' => 'pi_bf']],
        ]],
        payouts: [['id' => 'po_bf1', 'amount' => 941, 'currency' => 'usd', 'arrival_date' => Carbon::parse('2026-07-10')->timestamp, 'status' => 'paid']],
    );

    test()->artisan('stripe:backfill-payouts')->assertSuccessful();

    // pi_bf matches no job_payments row (none created in this test) -> partial,
    // not resolved. That distinction isn't what this test is about — see the
    // "resolves a payout" test above for resolved-vs-partial coverage.
    $payout = StripePayout::where('stripe_payout_id', 'po_bf1')->firstOrFail();
    expect($payout->resolution_status)->toBe(StripePayout::STATUS_PARTIAL)
        ->and(StripePayoutLine::where('stripe_payout_id', $payout->id)->count())->toBe(1);

    // Rerun: listAllPayouts runs again (cheap, fine), but
    // listPayoutBalanceTransactions must NEVER be called again for an
    // already-resolved payout — enforced via ->never() on a fresh mock.
    $mock = Mockery::mock(StripeClient::class)->makePartial();
    $mock->shouldReceive('listAllPayouts')->andReturn([
        ['id' => 'po_bf1', 'amount' => 941, 'currency' => 'usd', 'arrival_date' => Carbon::parse('2026-07-10')->timestamp, 'status' => 'paid'],
    ]);
    $mock->shouldReceive('listPayoutBalanceTransactions')->never();
    app()->instance(StripeClient::class, $mock);

    test()->artisan('stripe:backfill-payouts')->assertSuccessful();

    expect(StripePayout::where('stripe_payout_id', 'po_bf1')->count())->toBe(1)
        ->and(StripePayoutLine::where('stripe_payout_id', $payout->id)->count())->toBe(1);
});

it('skips payouts arriving before --since', function () {
    Setting::put('stripe_secret_key', 'sk_test_x', sensitive: true);

    fakeStripeClientOps(
        transactions: [],
        payouts: [
            ['id' => 'po_old', 'amount' => 100, 'currency' => 'usd', 'arrival_date' => Carbon::parse('2026-01-01')->timestamp, 'status' => 'paid'],
            ['id' => 'po_new', 'amount' => 200, 'currency' => 'usd', 'arrival_date' => Carbon::parse('2026-07-20')->timestamp, 'status' => 'paid'],
        ],
    );

    test()->artisan('stripe:backfill-payouts', ['--since' => '2026-07-01'])->assertSuccessful();

    expect(StripePayout::where('stripe_payout_id', 'po_old')->exists())->toBeFalse()
        ->and(StripePayout::where('stripe_payout_id', 'po_new')->exists())->toBeTrue();
});

it('fails cleanly when Stripe is not configured', function () {
    test()->artisan('stripe:backfill-payouts')->assertFailed();

    expect(StripePayout::count())->toBe(0);
});

// ---- stripe:match-payouts / StripePayoutReconciler ----

it('links a resolved payout to its exact-amount, same-day Chase deposit', function () {
    $txn = opsBankTxn(['amount_cents' => -49976, 'date' => '2026-07-20']);
    $payout = StripePayout::create([
        'stripe_payout_id' => 'po_match', 'amount_cents' => 49976, 'currency' => 'usd',
        'arrival_date' => '2026-07-20', 'status' => 'paid', 'resolution_status' => StripePayout::STATUS_RESOLVED,
    ]);

    test()->artisan('stripe:match-payouts')->assertSuccessful();

    expect($payout->refresh()->bank_transaction_id)->toBe($txn->id);
});

it('falls back to a +/-2 day window when nothing matches the exact arrival date', function () {
    $txn = opsBankTxn(['amount_cents' => -30000, 'date' => '2026-07-22']); // 2 days after arrival
    $payout = StripePayout::create([
        'stripe_payout_id' => 'po_window', 'amount_cents' => 30000, 'currency' => 'usd',
        'arrival_date' => '2026-07-20', 'status' => 'paid', 'resolution_status' => StripePayout::STATUS_RESOLVED,
    ]);

    test()->artisan('stripe:match-payouts')->assertSuccessful();

    expect($payout->refresh()->bank_transaction_id)->toBe($txn->id);
});

it('leaves an ambiguous payout unmatched when two deposits fit equally', function () {
    opsBankTxn(['amount_cents' => -15000, 'date' => '2026-07-20', 'name' => 'STRIPE A']);
    opsBankTxn(['amount_cents' => -15000, 'date' => '2026-07-20', 'name' => 'STRIPE B', 'plaid_transaction_id' => 'txn-other']);
    $payout = StripePayout::create([
        'stripe_payout_id' => 'po_ambiguous', 'amount_cents' => 15000, 'currency' => 'usd',
        'arrival_date' => '2026-07-20', 'status' => 'paid', 'resolution_status' => StripePayout::STATUS_RESOLVED,
    ]);

    test()->artisan('stripe:match-payouts')->assertSuccessful();

    expect($payout->refresh()->bank_transaction_id)->toBeNull();
});

it('never claims a deposit already claimed by the old heuristic matcher', function () {
    opsBankTxn(['amount_cents' => -8000, 'date' => '2026-07-20', 'match_status' => BankTransaction::STATUS_MATCHED]);
    $payout = StripePayout::create([
        'stripe_payout_id' => 'po_taken', 'amount_cents' => 8000, 'currency' => 'usd',
        'arrival_date' => '2026-07-20', 'status' => 'paid', 'resolution_status' => StripePayout::STATUS_RESOLVED,
    ]);

    test()->artisan('stripe:match-payouts')->assertSuccessful();

    expect($payout->refresh()->bank_transaction_id)->toBeNull();
});

it('only stamps bank_transaction_id - it never creates a BankTransactionMatch row', function () {
    opsBankTxn(['amount_cents' => -4200, 'date' => '2026-07-20']);
    StripePayout::create([
        'stripe_payout_id' => 'po_no_match_rows', 'amount_cents' => 4200, 'currency' => 'usd',
        'arrival_date' => '2026-07-20', 'status' => 'paid', 'resolution_status' => StripePayout::STATUS_RESOLVED,
    ]);

    test()->artisan('stripe:match-payouts')->assertSuccessful();

    expect(BankTransactionMatch::count())->toBe(0);
});
