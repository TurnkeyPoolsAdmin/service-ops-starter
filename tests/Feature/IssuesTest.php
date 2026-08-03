<?php

use App\Enums\IssueType;
use App\Filament\Pages\Issues;
use App\Models\BankTransaction;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Issue;
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
        'name' => 'Super Op', 'email' => 'superop@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->actingAs($this->actor);
    $this->lifecycle = app(JobLifecycle::class);
});

/** A fresh job via the normal intake path, so it has a job_number, entity, etc. */
function issueJob($test, array $overrides = []): Job
{
    return $test->lifecycle->createFromIntake(array_merge([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+1818555'.random_int(1000, 9999),
        'first_name' => 'Alan',
        'quote_amount_cents' => 45000,
    ], $overrides), $test->actor);
}

/** A posted bank transaction, for tests that raise a transaction_mismatch issue directly. */
function issueBankTxn($test, array $overrides = []): BankTransaction
{
    $item = PlaidItem::firstOrCreate(
        ['item_id' => 'itm_issues_test'],
        ['access_token' => 'tok', 'institution_name' => 'Chase'],
    );

    return BankTransaction::create(array_merge([
        'plaid_item_id' => $item->id,
        'plaid_transaction_id' => 'txn_issues_'.fake()->unique()->uuid(),
        'plaid_account_id' => 'acct_1',
        'amount_cents' => 15000,
        'date' => '2026-07-01',
        'name' => 'Zelle payment to Jose Aguirre',
    ], $overrides));
}

// ---- 10: deleteCompletely soft-deletes + raises job_deleted ----

it('soft-deletes the job, raises a job_deleted issue with a snapshot, and hides the job everywhere', function () {
    $job = issueJob($this);
    $jobId = $job->id;
    $jobNumber = $job->job_number;
    $customerName = $job->customer_name_snapshot;

    $this->lifecycle->deleteCompletely($job, $this->actor);

    expect(Job::find($jobId))->toBeNull()
        ->and(Job::query()->pluck('id'))->not->toContain($jobId);

    $trashed = Job::withTrashed()->find($jobId);
    expect($trashed)->not->toBeNull()
        ->and($trashed->trashed())->toBeTrue();

    $issue = Issue::open()->where('type', IssueType::JobDeleted->value)->first();
    expect($issue)->not->toBeNull()
        ->and($issue->subject_type)->toBe(Job::class)
        ->and($issue->subject_id)->toBe($jobId)
        ->and($issue->raised_by)->toBe($this->actor->id)
        ->and($issue->details['job_number'])->toBe($jobNumber)
        ->and($issue->details['customer_name'])->toBe($customerName)
        ->and($issue->details['quote_cents'])->toBe(45000)
        ->and($issue->details['actor_name'])->toBe($this->actor->name);
});

// ---- 11: cancel()/markLost() raise job_canceled, idempotently ----

it('cancel() raises a job_canceled issue with the reason and never duplicates it', function () {
    $job = issueJob($this);

    $this->lifecycle->cancel($job, 'Customer changed their mind', $this->actor);

    $issue = Issue::open()->where('type', IssueType::JobCanceled->value)->where('subject_id', $job->id)->first();
    expect($issue)->not->toBeNull()
        ->and($issue->details['reason'])->toBe('Customer changed their mind')
        ->and($issue->details['job_number'])->toBe($job->job_number);

    // Cancelling again (e.g. a double click) must not create a second open issue.
    $this->lifecycle->cancel($job->refresh(), 'Still canceled', $this->actor);

    expect(Issue::where('type', IssueType::JobCanceled->value)->where('subject_id', $job->id)->count())->toBe(1);
});

it('markLost raises a job_canceled issue regardless of whether the job had been scheduled', function () {
    $scheduledJob = issueJob($this, ['first_name' => 'Booked']);
    $this->lifecycle->schedule($scheduledJob, Carbon::parse('2026-07-10 09:00', 'America/Los_Angeles'), $this->actor);

    $this->lifecycle->markLost($scheduledJob->refresh(), 'No-show', $this->actor);

    expect(Issue::open()->where('type', IssueType::JobCanceled->value)->where('subject_id', $scheduledJob->id)->exists())
        ->toBeTrue();

    // A lead marked lost straight out of pending_schedule (never booked) still shows up.
    $pendingJob = issueJob($this, ['first_name' => 'NeverBooked']);
    $this->lifecycle->markLost($pendingJob, 'Not interested', $this->actor);

    expect(Issue::open()->where('type', IssueType::JobCanceled->value)->where('subject_id', $pendingJob->id)->exists())
        ->toBeTrue();
});

// ---- 12: Issues page is super-admin only ----

it('only super_admin passes canAccess() for the Issues page', function () {
    expect(Issues::canAccess())->toBeTrue();

    $admin = Employee::create([
        'name' => 'Admin', 'email' => 'issues-admin@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $admin->assignRole('admin');

    $dispatch = Employee::create([
        'name' => 'Dispatch', 'email' => 'issues-dispatch@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $dispatch->assignRole('dispatch');

    $finance = Employee::create([
        'name' => 'Finance', 'email' => 'issues-finance@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $finance->assignRole('finance');

    // Separate actingAs() calls (not a shared HTTP client) so each check is independent.
    $this->actingAs($admin);
    expect(Issues::canAccess())->toBeFalse();

    $this->actingAs($dispatch);
    expect(Issues::canAccess())->toBeFalse();

    $this->actingAs($finance);
    expect(Issues::canAccess())->toBeFalse();

    $this->actingAs($this->actor);
    expect(Issues::canAccess())->toBeTrue();
});

it('403s a non-super_admin who requests the Issues page directly', function () {
    $dispatch = Employee::create([
        'name' => 'Dispatch', 'email' => 'issues-dispatch-http@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $dispatch->assignRole('dispatch');

    $this->actingAs($dispatch);
    $this->get(Issues::getUrl())->assertForbidden();
});

it('lets a super_admin load the Issues page', function () {
    $this->get(Issues::getUrl())->assertSuccessful();
});

// ---- 13: clearing purges a job_deleted subject, but nothing else ----

it('clearing a job_deleted issue permanently purges the trashed job and stamps the clear', function () {
    $job = issueJob($this);
    $jobId = $job->id;
    $this->lifecycle->deleteCompletely($job, $this->actor);

    $issue = Issue::open()->where('type', IssueType::JobDeleted->value)->where('subject_id', $jobId)->first();

    Livewire::test(Issues::class)
        ->set('subview', 'deleted')
        ->callTableAction('clear', $issue, data: ['resolution_note' => 'Reviewed — purge it'])
        ->assertHasNoTableActionErrors();

    expect(Job::withTrashed()->find($jobId))->toBeNull();

    $issue->refresh();
    expect($issue->status)->toBe('cleared')
        ->and($issue->cleared_by)->toBe($this->actor->id)
        ->and($issue->cleared_at)->not->toBeNull()
        ->and($issue->resolution_note)->toBe('Reviewed — purge it')
        ->and($issue->details['job_number'])->not->toBeNull(); // snapshot still readable after purge
});

it('clearing a cancelled-job or mismatch issue purges nothing', function () {
    $job = issueJob($this);
    $this->lifecycle->cancel($job, 'test reason', $this->actor);
    $cancelIssue = Issue::open()->where('type', IssueType::JobCanceled->value)->where('subject_id', $job->id)->first();

    Livewire::test(Issues::class)
        ->set('subview', 'canceled')
        ->callTableAction('clear', $cancelIssue, data: [])
        ->assertHasNoTableActionErrors();

    expect(Job::find($job->id))->not->toBeNull();
    expect($cancelIssue->refresh()->status)->toBe('cleared');

    $txn = issueBankTxn($this);
    $mismatchIssue = Issue::raise(IssueType::TransactionMismatch, $txn, ['note' => 'x'], $this->actor->id);

    Livewire::test(Issues::class)
        ->set('subview', 'mismatch')
        ->callTableAction('clear', $mismatchIssue, data: [])
        ->assertHasNoTableActionErrors();

    expect(BankTransaction::find($txn->id))->not->toBeNull();
    expect($mismatchIssue->refresh()->status)->toBe('cleared');
});

// ---- 14: subview queries only ever show their own type/status ----

it('shows only the matching type+open issues per subview, and cleared issues nowhere at all', function () {
    $deletedJob = issueJob($this, ['first_name' => 'Deleted']);
    $this->lifecycle->deleteCompletely($deletedJob, $this->actor);
    $deletedIssue = Issue::where('type', IssueType::JobDeleted->value)->where('subject_id', $deletedJob->id)->first();

    $canceledJob = issueJob($this, ['first_name' => 'Canceled']);
    $this->lifecycle->cancel($canceledJob, 'reason', $this->actor);
    $canceledIssue = Issue::where('type', IssueType::JobCanceled->value)->where('subject_id', $canceledJob->id)->first();

    $txn = issueBankTxn($this);
    $mismatchIssue = Issue::raise(IssueType::TransactionMismatch, $txn, [], $this->actor->id);

    // An already-cleared issue stays in the table as an audit row but has no
    // UI — it must not surface on ANY subview.
    $txn2 = issueBankTxn($this, ['plaid_transaction_id' => 'txn_issues_cleared']);
    $clearedIssue = Issue::raise(IssueType::TransactionMismatch, $txn2, [], $this->actor->id);
    $clearedIssue->clear($this->actor->id, 'already handled');

    Livewire::test(Issues::class)
        ->set('subview', 'deleted')
        ->assertCanSeeTableRecords([$deletedIssue])
        ->assertCanNotSeeTableRecords([$canceledIssue, $mismatchIssue, $clearedIssue]);

    Livewire::test(Issues::class)
        ->set('subview', 'canceled')
        ->assertCanSeeTableRecords([$canceledIssue])
        ->assertCanNotSeeTableRecords([$deletedIssue, $mismatchIssue, $clearedIssue]);

    Livewire::test(Issues::class)
        ->set('subview', 'mismatch')
        ->assertCanSeeTableRecords([$mismatchIssue])
        ->assertCanNotSeeTableRecords([$deletedIssue, $canceledIssue, $clearedIssue]);

    // An unknown/legacy subview value (e.g. an old "cleared" bookmark) falls
    // back to a real tab on mount rather than erroring.
    Livewire::test(Issues::class)
        ->set('subview', 'cleared')
        ->assertCanNotSeeTableRecords([$clearedIssue]);
});

/**
 * matched_transaction_fields_changed (App\Console\Commands\PullBankTransactions,
 * 2026-07-31 drift-detection fix): confirms the new type actually RENDERS
 * through the Filament table on its own subview - whatHappened()/label()/
 * color() are all exhaustive matches on IssueType, so a missing arm would
 * throw the moment this row is displayed, not just when Issue::raise() is
 * called. Table-render coverage, not just a raise() unit check.
 */
it('renders a matched_transaction_fields_changed issue on its own subview', function () {
    $txn = issueBankTxn($this);
    $issue = Issue::raise(IssueType::MatchedTransactionFieldsChanged, $txn, [
        'txn_description' => $txn->name,
        'match_status' => BankTransaction::STATUS_MATCHED,
        'changed_fields' => 'amount_cents',
        'old_amount_cents' => 33000,
        'new_amount_cents' => 25820,
    ], $this->actor->id);

    Livewire::test(Issues::class)
        ->set('subview', 'fields_changed')
        ->assertCanSeeTableRecords([$issue])
        ->assertSee('Jose Aguirre'); // txn_description made it into the rendered row
});
