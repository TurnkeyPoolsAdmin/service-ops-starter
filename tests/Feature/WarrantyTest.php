<?php

use App\Enums\PaymentMethod;
use App\Enums\TemplateKey;
use App\Filament\Resources\Jobs\Pages\ViewJob;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\WarrantyClaim;
use App\Services\JobLifecycle;
use App\Services\Warranty\WarrantyService;
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
    $this->warranty = app(WarrantyService::class);
});

/** A job whose work is done (the warranty entry point). */
function workDoneJob($test): Job
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
    $test->lifecycle->markWorkComplete($job->refresh(), $test->actor);

    return $job->refresh();
}

it('opens a warranty claim on a completed job, defaulting to the original tech', function () {
    $job = workDoneJob($this);

    $claim = $this->warranty->openForJob($job, 'Windshield whistles at highway speed', $this->actor);

    expect($claim->status)->toBe(WarrantyClaim::STATUS_OPEN)
        ->and($claim->technician_id)->toBe($this->tech->id)
        ->and($claim->customer_id)->toBe($job->customer_id)
        ->and($claim->entity_id)->toBe($job->entity_id);
});

it('refuses a claim before the work is done, and a second live claim', function () {
    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185559999',
        'first_name' => 'Beth',
    ], $this->actor);

    expect(fn () => $this->warranty->openForJob($job, 'Leak', $this->actor))
        ->toThrow(RuntimeException::class);

    $done = workDoneJob($this);
    $this->warranty->openForJob($done, 'Leak', $this->actor);
    expect(fn () => $this->warranty->openForJob($done, 'Another leak', $this->actor))
        ->toThrow(RuntimeException::class);
});

it('scheduling the return visit sends ONE generic text with the visit window', function () {
    $job = workDoneJob($this);
    $claim = $this->warranty->openForJob($job, 'Wind noise', $this->actor);

    $msg = $this->warranty->schedule(
        $claim,
        Carbon::parse('2026-07-14 08:00', 'America/Los_Angeles'),
        $this->actor,
        technicianId: $this->tech->id,
    );

    expect($claim->refresh()->status)->toBe(WarrantyClaim::STATUS_SCHEDULED)
        ->and($msg)->not->toBeNull()
        ->and($msg->rendered_body)->toContain('Hi Alan')
        ->and($msg->rendered_body)->toContain('Tuesday, July 14th between 8am-10am')
        ->and($msg->rendered_body)->toContain('issue you mentioned')
        // Generic language only — the word "warranty" never reaches the customer.
        ->and(str_contains(strtolower($msg->rendered_body), 'warranty'))->toBeFalse();

    // Re-booking the SAME time (double-click) doesn't send a second text...
    $this->warranty->schedule($claim, Carbon::parse('2026-07-14 08:00', 'America/Los_Angeles'), $this->actor);
    expect($job->scheduledMessages()->where('template_key', TemplateKey::WarrantyVisitScheduled->value)->count())->toBe(1);

    // ...but moving it to a new time announces the new time.
    $this->warranty->schedule($claim, Carbon::parse('2026-07-15 10:00', 'America/Los_Angeles'), $this->actor, exactTime: true);
    $second = $job->scheduledMessages()
        ->where('template_key', TemplateKey::WarrantyVisitScheduled->value)
        ->orderByDesc('id')->first();
    expect($job->scheduledMessages()->where('template_key', TemplateKey::WarrantyVisitScheduled->value)->count())->toBe(2)
        ->and($second->rendered_body)->toContain('Wednesday, July 15th at 10am');
});

it('schedules without a text for an opted-out customer', function () {
    $job = workDoneJob($this);
    $job->customer->update(['do_not_text' => true]);
    $claim = $this->warranty->openForJob($job, 'Chip near the edge', $this->actor);

    $msg = $this->warranty->schedule($claim, Carbon::parse('2026-07-14 08:00', 'America/Los_Angeles'), $this->actor);

    expect($msg)->toBeNull()
        ->and($claim->refresh()->status)->toBe(WarrantyClaim::STATUS_SCHEDULED);
});

it('resolves and cancels claims', function () {
    $job = workDoneJob($this);
    $claim = $this->warranty->openForJob($job, 'Molding lifting', $this->actor);

    $this->warranty->resolve($claim, 'Re-seated the molding', $this->actor);
    expect($claim->refresh()->status)->toBe(WarrantyClaim::STATUS_RESOLVED)
        ->and($claim->resolution_notes)->toBe('Re-seated the molding')
        ->and($claim->resolved_at)->not->toBeNull();

    // A resolved job can take a NEW claim if the customer calls again.
    $second = $this->warranty->openForJob($job, 'Still lifting', $this->actor);
    $this->warranty->cancel($second, 'customer withdrew', $this->actor);
    expect($second->refresh()->status)->toBe(WarrantyClaim::STATUS_CANCELED);
});

it('the job action opens a claim and then hides itself while one is live', function () {
    $job = workDoneJob($this);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('warrantyFixNeeded', ['issue' => 'Rattle in the door panel']);

    expect($job->warrantyClaims()->count())->toBe(1)
        ->and($job->warrantyClaims()->first()->issue)->toBe('Rattle in the door panel');

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->assertActionHidden('warrantyFixNeeded');
});

it('the warranty visit never creates money movement on the job', function () {
    $job = workDoneJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);
    $before = $job->refresh()->only(['amount_collected_cents', 'tech_pay_amount_cents', 'quote_amount_cents']);

    $claim = $this->warranty->openForJob($job, 'Sensor fault', $this->actor);
    $this->warranty->schedule($claim, Carbon::parse('2026-07-14 08:00', 'America/Los_Angeles'), $this->actor);
    $this->warranty->resolve($claim, null, $this->actor);

    expect($job->refresh()->only(['amount_collected_cents', 'tech_pay_amount_cents', 'quote_amount_cents']))
        ->toBe($before);
});
