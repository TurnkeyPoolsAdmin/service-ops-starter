<?php

use App\Enums\BankMatchKind;
use App\Enums\PaymentMethod;
use App\Enums\TransactionMatchStatus;
use App\Filament\Resources\Jobs\Pages\ViewJob;
use App\Models\BankTransaction;
use App\Models\BankTransactionMatch;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\PlaidItem;
use App\Services\JobLifecycle;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->tech = Employee::create([
        'name' => 'Tech', 'email' => 'tech@test.local', 'active' => true,
        'job_functions' => ['technician'], 'phone' => '+18185550000',
    ]);

    $this->actingAs($this->actor);
    $this->lifecycle = app(JobLifecycle::class);
});

function arJob($test): Job
{
    $job = $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Alan',
        'quote_amount_cents' => 45000,
    ], $test->actor);

    $test->lifecycle->schedule(
        $job,
        Carbon::parse('2026-07-08 09:00', 'America/Los_Angeles'),
        $test->actor,
        technicianId: $test->tech->id,
    );

    // Work done, but nothing paid yet.
    $job->refresh()->update(['work_completed_at' => now()]);

    return $job->refresh();
}

it('lists a completed unpaid job as awaiting payment', function () {
    $job = arJob($this);

    expect(Job::awaitingPayment()->pluck('id'))->toContain($job->id)
        ->and(Job::collectedUnconfirmed()->pluck('id'))->not->toContain($job->id)
        ->and($job->isPaymentReceiptConfirmed())->toBeFalse();
});

it('moves a non-cash payment into collected-unconfirmed until confirmed', function () {
    $job = arJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);
    $job->refresh();

    expect(Job::awaitingPayment()->pluck('id'))->not->toContain($job->id)
        ->and(Job::collectedUnconfirmed()->pluck('id'))->toContain($job->id)
        ->and($job->isPaymentReceiptConfirmed())->toBeFalse();

    $this->lifecycle->confirmPaymentReceived($job, $this->actor);
    $job->refresh();

    expect($job->payment_confirmed_at)->not->toBeNull()
        ->and($job->payment_confirmed_by)->toBe($this->actor->id)
        ->and(Job::collectedUnconfirmed()->pluck('id'))->not->toContain($job->id)
        ->and($job->isPaymentReceiptConfirmed())->toBeTrue();
});

it('never lists a cash payment in AR (out of scope)', function () {
    $job = arJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Cash, null, null, $this->actor);
    $job->refresh();

    expect(Job::collectedUnconfirmed()->pluck('id'))->not->toContain($job->id)
        ->and($job->isPaymentReceiptConfirmed())->toBeTrue();
});

it('clears a non-cash payment from AR once a Chase transaction is matched', function () {
    $job = arJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);
    $job->refresh();

    expect(Job::collectedUnconfirmed()->pluck('id'))->toContain($job->id);

    $item = PlaidItem::create(['item_id' => 'itm_1', 'access_token' => 'tok', 'institution_name' => 'Chase']);
    $txn = BankTransaction::create([
        'plaid_item_id' => $item->id,
        'plaid_transaction_id' => 'txn_1',
        'plaid_account_id' => 'acct_1',
        'amount_cents' => -45000, // money IN (deposit)
        'date' => '2026-07-08',
        'name' => 'Zelle payment from Alan',
        'match_status' => BankTransaction::STATUS_MATCHED,
    ]);
    BankTransactionMatch::create([
        'bank_transaction_id' => $txn->id,
        'kind' => BankMatchKind::JobPayment->value,
        'job_id' => $job->id,
        'source' => 'auto',
    ]);

    $job->load('bankMatches.bankTransaction');

    expect(Job::collectedUnconfirmed()->pluck('id'))->not->toContain($job->id)
        ->and($job->isPaymentReceiptConfirmed())->toBeTrue()
        ->and($job->hasBankConfirmedPayment())->toBeTrue();
});

function bankMatchFor(Job $job, int $amountCents, string $status = BankTransaction::STATUS_MATCHED): BankTransaction
{
    $item = PlaidItem::firstOrCreate(
        ['item_id' => 'itm_ar'],
        ['access_token' => 'tok', 'institution_name' => 'Chase'],
    );

    $txn = BankTransaction::create([
        'plaid_item_id' => $item->id,
        'plaid_transaction_id' => 'txn_'.fake()->unique()->uuid(),
        'plaid_account_id' => 'acct_1',
        'amount_cents' => -$amountCents, // Plaid sign: negative = money IN
        'date' => '2026-07-08',
        'name' => 'Zelle payment from Alan',
        'match_status' => $status,
    ]);

    BankTransactionMatch::create([
        'bank_transaction_id' => $txn->id,
        'kind' => BankMatchKind::JobPayment->value,
        'job_id' => $job->id,
        'source' => 'auto',
    ]);

    return $txn;
}

it('reports transactionMatchStatus as awaiting until a transaction is matched', function () {
    $job = arJob($this);
    expect($job->transactionMatchStatus())->toBeNull(); // unpaid → n/a

    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);
    $job->refresh()->load('bankMatches.bankTransaction');

    expect($job->transactionMatchStatus())->toBe(TransactionMatchStatus::AwaitingMatch);
});

it('confirms the job when a matched transaction reconciles to the collected amount', function () {
    $job = arJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);

    bankMatchFor($job->refresh(), 45000);
    $job->load('bankMatches.bankTransaction');

    expect($job->transactionMatchStatus())->toBe(TransactionMatchStatus::Confirmed)
        ->and($job->hasBankConfirmedPayment())->toBeTrue()
        ->and(Job::collectedUnconfirmed()->pluck('id'))->not->toContain($job->id);
});

it('flags a matched transaction whose amount is off as a mismatch and keeps it in AR', function () {
    $job = arJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);

    // $500 came in against a $450 collected payment — $50 off, well past ±$5.
    bankMatchFor($job->refresh(), 50000);
    $job->load('bankMatches.bankTransaction');

    expect($job->transactionMatchStatus())->toBe(TransactionMatchStatus::AmountMismatch)
        ->and($job->hasBankConfirmedPayment())->toBeFalse()
        ->and($job->isPaymentReceiptConfirmed())->toBeFalse()
        ->and(Job::collectedUnconfirmed()->pluck('id'))->toContain($job->id);
});

it('reconciles two transactions summing to one job payment', function () {
    $job = arJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);

    // Customer sent two Zelle payments — $225 + $225 = $450.
    bankMatchFor($job->refresh(), 22500);
    bankMatchFor($job->refresh(), 22500);
    $job->load('bankMatches.bankTransaction');

    expect($job->matchedTransactionCents())->toBe(45000)
        ->and($job->transactionMatchStatus())->toBe(TransactionMatchStatus::Confirmed)
        ->and(Job::collectedUnconfirmed()->pluck('id'))->not->toContain($job->id);
});

it('confirms a payment received from the job actions menu', function () {
    $job = arJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);
    $job->refresh();

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('confirmPaymentReceived')
        ->assertHasNoActionErrors();

    expect($job->refresh()->isPaymentReceiptConfirmed())->toBeTrue()
        ->and(Job::collectedUnconfirmed()->pluck('id'))->not->toContain($job->id);
});

it('confirmPaymentReceived is idempotent and a no-op on unpaid jobs', function () {
    $job = arJob($this);

    // Unpaid: no-op, no stamp.
    $this->lifecycle->confirmPaymentReceived($job, $this->actor);
    expect($job->refresh()->payment_confirmed_at)->toBeNull();

    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Venmo, null, null, $this->actor);
    $job->refresh();
    $this->lifecycle->confirmPaymentReceived($job, $this->actor);
    $first = $job->refresh()->payment_confirmed_at;
    $this->lifecycle->confirmPaymentReceived($job, $this->actor);

    expect($job->refresh()->payment_confirmed_at->eq($first))->toBeTrue();
});
