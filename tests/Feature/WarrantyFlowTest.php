<?php

use App\Enums\IssueType;
use App\Enums\PaymentMethod;
use App\Enums\TemplateKey;
use App\Models\BankTransaction;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Issue;
use App\Models\Job;
use App\Models\PlaidItem;
use App\Models\TechSettlement;
use App\Models\WarrantyClaim;
use App\Services\BankMatcher;
use App\Services\JobLifecycle;
use App\Services\Telegram\UnscheduledWarrantyAlert;
use App\Services\Telegram\WarrantyOverdueCompletionAlert;
use App\Services\Warranty\WarrantyService;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->tech = Employee::create([
        'name' => 'Jose Ramirez', 'email' => 'jose@test.local', 'active' => true,
        'job_functions' => ['technician'], 'phone' => '+18185550000',
    ]);
    $this->otherTech = Employee::create([
        'name' => 'Mike Lee', 'email' => 'mike@test.local', 'active' => true,
        'job_functions' => ['technician'], 'phone' => '+18185551111',
    ]);

    $this->actingAs($this->actor);
    $this->lifecycle = app(JobLifecycle::class);
    $this->warranty = app(WarrantyService::class);
    // The immediate "warranty filed" Telegram post is a no-op here: the bot has
    // no token/chat id configured in tests, so TelegramNotifier::configured() is
    // false and nothing is sent.
});

function warrantyReadyJob($test, ?int $techId = null): Job
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
        technicianId: $techId ?? $test->tech->id,
    );
    $test->lifecycle->markWorkComplete($job->refresh(), $test->actor);

    return $job->refresh();
}

it('captures the original tech when a claim opens', function () {
    $job = warrantyReadyJob($this);
    $claim = $this->warranty->openForJob($job, 'Wind noise', $this->actor);

    expect($claim->original_technician_id)->toBe($this->tech->id)
        ->and($claim->technician_id)->toBe($this->tech->id);
});

it('raises a tech-changed Issue when a different tech is scheduled', function () {
    $job = warrantyReadyJob($this);
    $claim = $this->warranty->openForJob($job, 'Leak', $this->actor);

    // Same tech — no issue.
    $this->warranty->schedule($claim, Carbon::parse('2026-07-20 08:00', 'America/Los_Angeles'), $this->actor, technicianId: $this->tech->id);
    expect(Issue::query()->open()->where('type', IssueType::WarrantyTechChanged->value)->count())->toBe(0);

    // Reschedule with a DIFFERENT tech — issue raised.
    $this->warranty->schedule($claim->refresh(), Carbon::parse('2026-07-21 08:00', 'America/Los_Angeles'), $this->actor, technicianId: $this->otherTech->id);

    $issue = Issue::query()->open()->where('type', IssueType::WarrantyTechChanged->value)->first();
    expect($issue)->not->toBeNull()
        ->and($issue->subject_id)->toBe($job->id)
        ->and($issue->details['original_tech'])->toBe('Jose Ramirez')
        ->and($issue->details['assigned_tech'])->toBe('Mike Lee');
});

it('raises an extra-pay Issue and stores the pay when the visit is paid', function () {
    $job = warrantyReadyJob($this);
    $claim = $this->warranty->openForJob($job, 'Rattle', $this->actor);

    $this->warranty->schedule(
        $claim,
        Carbon::parse('2026-07-20 08:00', 'America/Los_Angeles'),
        $this->actor,
        technicianId: $this->tech->id,
        techPayCents: 6000,
    );

    expect($claim->refresh()->tech_pay_cents)->toBe(6000)
        ->and($claim->hasTechPay())->toBeTrue();

    $issue = Issue::query()->open()->where('type', IssueType::WarrantyTechPay->value)->first();
    expect($issue)->not->toBeNull()
        ->and($issue->details['tech_pay_cents'])->toBe(6000);
});

it('schedules customer AND tech reminders for the visit', function () {
    // Freeze "now" a few days before the visit so both the night-before and
    // morning-of reminders are in the future regardless of the wall clock.
    Carbon::setTestNow(Carbon::parse('2026-07-20 09:00', 'America/Los_Angeles'));

    $job = warrantyReadyJob($this);
    $claim = $this->warranty->openForJob($job, 'Chip', $this->actor);

    $this->warranty->schedule($claim, Carbon::parse('2026-07-25 08:00', 'America/Los_Angeles'), $this->actor, technicianId: $this->tech->id);

    // template_key casts to the TemplateKey enum, so compare on ->value.
    $keys = $job->scheduledMessages()->pluck('template_key')->map(fn ($k) => $k->value)->all();
    expect($keys)->toContain(TemplateKey::WarrantyReminderNightBefore->value)
        ->toContain(TemplateKey::WarrantyReminderMorningOf->value)
        ->toContain(TemplateKey::TechWarrantyReminderNightBefore->value)
        ->toContain(TemplateKey::TechWarrantyReminderMorningOf->value);

    // The tech reminder carries the visit time in the body. Techs default to
    // Spanish, so it renders "25 de julio" — assert the day number, not "July".
    $techReminder = $job->scheduledMessages()
        ->where('template_key', TemplateKey::TechWarrantyReminderMorningOf->value)->first();
    expect($techReminder->rendered_body)->toContain('Jose')
        ->and($techReminder->rendered_body)->toContain('25');

    Carbon::setTestNow();
});

it('rescheduling to a new time replaces the reminders', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-20 09:00', 'America/Los_Angeles'));

    $job = warrantyReadyJob($this);
    $claim = $this->warranty->openForJob($job, 'Chip', $this->actor);

    $this->warranty->schedule($claim, Carbon::parse('2026-07-25 08:00', 'America/Los_Angeles'), $this->actor, technicianId: $this->tech->id);
    $this->warranty->schedule($claim->refresh(), Carbon::parse('2026-07-27 08:00', 'America/Los_Angeles'), $this->actor, technicianId: $this->tech->id);

    // Old reminders canceled, new ones queued — exactly one unsent morning-of each.
    $unsentCustomer = $job->scheduledMessages()
        ->where('template_key', TemplateKey::WarrantyReminderMorningOf->value)
        ->whereNull('sent_at')->whereNull('canceled_at')->count();
    expect($unsentCustomer)->toBe(1);

    Carbon::setTestNow();
});

it('sends a completion text with no review drip when marked complete', function () {
    $job = warrantyReadyJob($this);
    // The original job completion already opened the call-first review queue —
    // capture its id so we can prove the warranty visit never touches it.
    $originalReviewRequestId = $job->reviewRequest()->value('id');

    $claim = $this->warranty->openForJob($job, 'Molding', $this->actor);
    $this->warranty->schedule($claim, Carbon::parse('2026-07-25 08:00', 'America/Los_Angeles'), $this->actor, technicianId: $this->tech->id);

    $this->warranty->resolve($claim->refresh(), 'Re-seated', $this->actor, sentiment: 'up');

    $complete = $job->scheduledMessages()->where('template_key', TemplateKey::WarrantyCompleted->value)->first();
    expect($complete)->not->toBeNull()
        ->and($complete->rendered_body)->toContain('all set')
        ->and(str_contains(strtolower($complete->rendered_body), 'review'))->toBeFalse()
        ->and($claim->refresh()->sentiment)->toBe('up')
        // The warranty visit's own completion opens/advances NO review request —
        // still exactly the one the original job completion opened, untouched.
        ->and($job->reviewRequest()->count())->toBe(1)
        ->and($job->reviewRequest()->value('id'))->toBe($originalReviewRequestId)
        ->and($job->reviewRequest->status)->toBe(\App\Models\ReviewRequest::STATUS_AWAITING_CALL);
});

it('warranty pay flows onto End-of-Day and settles as a bank-matchable payout', function () {
    $job = warrantyReadyJob($this);
    $claim = $this->warranty->openForJob($job, 'Redo seal', $this->actor);
    $this->warranty->schedule(
        $claim, Carbon::parse('2026-07-25 08:00', 'America/Los_Angeles'), $this->actor,
        technicianId: $this->tech->id, techPayCents: 7500,
    );

    // Not owed until the visit is completed.
    expect(WarrantyClaim::warrantyPayOwed()->count())->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-07-25 12:00', 'America/Los_Angeles'));
    $this->warranty->resolve($claim->refresh(), null, $this->actor, sentiment: 'up');

    expect($claim->refresh()->isWarrantyPayOwed())->toBeTrue()
        ->and(WarrantyClaim::warrantyPayOwed()->count())->toBe(1);

    $settlement = $this->warranty->markTechPaid($claim->refresh(), PaymentMethod::Zelle, null, $this->actor);

    expect($settlement)->not->toBeNull()
        ->and($settlement->type)->toBe('warranty_payout')
        ->and($settlement->amount_cents)->toBe(7500)
        ->and($settlement->warranty_claim_id)->toBe($claim->id)
        ->and($claim->refresh()->tech_paid_at)->not->toBeNull()
        ->and(WarrantyClaim::warrantyPayOwed()->count())->toBe(0);

    // A Chase Zelle for the same amount + day reconciles to it via the matcher.
    $item = PlaidItem::create([
        'item_id' => 'item-w', 'access_token' => 'access-w', 'institution_name' => 'Chase',
    ]);
    $txn = BankTransaction::create([
        'plaid_item_id' => $item->id,
        'plaid_transaction_id' => 'w1',
        'plaid_account_id' => 'a1',
        'account_name' => 'Chase Total Checking',
        'name' => 'Zelle payment to Jose Ramirez',
        'amount_cents' => 7500, // money out
        'date' => '2026-07-25',
        'pending' => false,
    ]);

    app(BankMatcher::class)->matchOne($txn->refresh());

    expect($txn->refresh()->matches()->where('tech_settlement_id', $settlement->id)->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('the unscheduled-warranty alert lists open claims and clears once scheduled', function () {
    $job = warrantyReadyJob($this);
    $claim = $this->warranty->openForJob($job, 'Wind noise near A-pillar', $this->actor);

    $body = app(UnscheduledWarrantyAlert::class)->build();
    expect($body)->not->toBeNull()
        ->and($body)->toContain('Alan')
        ->and($body)->toContain('Wind noise');

    $this->warranty->schedule($claim, Carbon::parse('2026-07-25 08:00', 'America/Los_Angeles'), $this->actor, technicianId: $this->tech->id);

    expect(app(UnscheduledWarrantyAlert::class)->build())->toBeNull();
});

it('the overdue-warranty alert flags a scheduled visit past its window + grace', function () {
    $job = warrantyReadyJob($this);
    $claim = $this->warranty->openForJob($job, 'Seal', $this->actor);
    $this->warranty->schedule($claim, Carbon::parse('2026-07-25 08:00', 'America/Los_Angeles'), $this->actor, technicianId: $this->tech->id);

    // Before the window + grace closes — nothing due.
    Carbon::setTestNow(Carbon::parse('2026-07-25 09:00', 'America/Los_Angeles'));
    expect(app(WarrantyOverdueCompletionAlert::class)->dueClaims()->count())->toBe(0);

    // 8-10am window + 2h grace → due at 12pm.
    Carbon::setTestNow(Carbon::parse('2026-07-25 12:30', 'America/Los_Angeles'));
    expect(app(WarrantyOverdueCompletionAlert::class)->dueClaims()->count())->toBe(1);

    Carbon::setTestNow();
});
