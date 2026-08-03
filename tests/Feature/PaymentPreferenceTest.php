<?php

use App\Enums\PaymentMethod;
use App\Enums\TemplateKey;
use App\Filament\Resources\Jobs\Pages\ViewJob;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\Setting;
use App\Services\JobLifecycle;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Http;
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

function scheduledJob($test): Job
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

    return $job->refresh();
}

/** Every Job Complete text shares the completion copy; only the payment paragraph differs. */
function expectCompletionCopy(string $body): void
{
    expect($body)->toContain('your glass service is completed')
        ->and($body)->toContain('leave it on for 48 hours');
}

it('texts the Job Complete - Zelle message (the original job_complete body) for a Zelle preference', function () {
    $job = scheduledJob($this);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('markWorkComplete', ['payment_preference' => PaymentMethod::Zelle->value]);

    $job->refresh();
    expect($job->isWorkComplete())->toBeTrue()
        ->and($job->payment_preference)->toBe(PaymentMethod::Zelle);

    $msg = $job->scheduledMessages()->where('template_key', TemplateKey::JobComplete->value)->first();
    expect($msg)->not->toBeNull()
        ->and($msg->rendered_body)->toContain('Zelle')
        ->and($msg->rendered_body)->toContain('$450.00');
    expectCompletionCopy($msg->rendered_body);
});

it('sends the Zelle text without any entity payment details configured', function () {
    // The body spells the Zelle account out in the copy rather than pulling a
    // {zelle_details} merge var, so an unset entity can no longer block the text.
    $job = scheduledJob($this);
    expect(Entity::where('code', 'LA')->value('zelle_details'))->toBeNull();

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('markWorkComplete', ['payment_preference' => PaymentMethod::Zelle->value]);

    expect($job->refresh()->scheduledMessages()
        ->where('template_key', TemplateKey::JobComplete->value)->exists())->toBeTrue();
});

it('texts its own Job Complete clone for Venmo, Cash and Check — and never the Zelle one', function (PaymentMethod $method, TemplateKey $key) {
    $job = scheduledJob($this);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('markWorkComplete', ['payment_preference' => $method->value]);

    $job->refresh();
    expect($job->isWorkComplete())->toBeTrue()
        ->and($job->payment_preference)->toBe($method);

    $msg = $job->scheduledMessages()->where('template_key', $key->value)->first();
    expect($msg)->not->toBeNull();
    expectCompletionCopy($msg->rendered_body);

    // The Zelle body (job_complete) must not also go out.
    expect($job->scheduledMessages()->where('template_key', TemplateKey::JobComplete->value)->exists())->toBeFalse();
})->with([
    'venmo' => [PaymentMethod::Venmo, TemplateKey::JobCompleteVenmo],
    'cash' => [PaymentMethod::Cash, TemplateKey::JobCompleteCash],
    'check' => [PaymentMethod::Check, TemplateKey::JobCompleteCheck],
]);

it('creates a card payment link and texts it inside the Job Complete - Credit Card body', function () {
    Setting::put('stripe_secret_key', 'sk_test_abc', sensitive: true);
    Http::fake([
        'https://api.stripe.com/v1/payment_links' => Http::response([
            'id' => 'plink_1', 'url' => 'https://buy.stripe.com/test_abc', 'active' => true,
        ]),
    ]);

    $job = scheduledJob($this);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('markWorkComplete', ['payment_preference' => PaymentMethod::Stripe->value]);

    $job->refresh();
    expect($job->isWorkComplete())->toBeTrue()
        ->and($job->payment_preference)->toBe(PaymentMethod::Stripe)
        ->and($job->stripe_invoice_url)->toBe('https://buy.stripe.com/test_abc');

    $msg = $job->scheduledMessages()->where('template_key', TemplateKey::JobCompleteCreditCard->value)->first();
    expect($msg)->not->toBeNull()
        ->and($msg->rendered_body)->toContain('https://buy.stripe.com/test_abc')
        ->and($msg->rendered_body)->toContain('$463.50'); // $450 + 3%
    expectCompletionCopy($msg->rendered_body);

    // Neither the standalone card-link text nor the Zelle body goes out.
    expect($job->scheduledMessages()
        ->whereIn('template_key', [TemplateKey::PaymentLink->value, TemplateKey::JobComplete->value])
        ->exists())->toBeFalse();
});

it('requires picking a payment preference on an unpaid job', function () {
    $job = scheduledJob($this);

    // The radio defaults to Zelle when the job has no stored preference — clear
    // it explicitly to exercise the "required" validation.
    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('markWorkComplete', ['payment_preference' => ''])
        ->assertHasActionErrors(['payment_preference' => 'required']);

    expect($job->refresh()->isWorkComplete())->toBeFalse();
});

it('defaults the payment preference radio to Zelle when none is stored', function () {
    $job = scheduledJob($this);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->mountAction('markWorkComplete')
        ->assertActionDataSet(['payment_preference' => PaymentMethod::Zelle->value]);
});

it('skips the question when the job is already paid, sending the payment-free completion text', function () {
    $job = scheduledJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);

    Livewire::test(ViewJob::class, ['record' => $job->refresh()->getRouteKey()])
        ->callAction('markWorkComplete')
        ->assertHasNoActionErrors();

    $job->refresh();
    // Already paid → the payment-free JobCompletePaid body, never the Zelle one.
    expect($job->isWorkComplete())->toBeTrue()
        ->and($job->scheduledMessages()->where('template_key', TemplateKey::JobCompletePaid->value)->exists())->toBeTrue()
        ->and($job->scheduledMessages()->where('template_key', TemplateKey::JobComplete->value)->exists())->toBeFalse();
});

it('opens no review request on a prepayment; Mark work complete opens it awaiting a call', function () {
    $job = scheduledJob($this); // scheduled, work NOT complete yet

    // Prepayment: recording payment never opens a review request — that only
    // happens at completion now.
    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('recordPayment', ['amount' => 450, 'payment_method' => PaymentMethod::Zelle->value])
        ->assertHasNoActionErrors();

    $job->refresh();
    expect($job->isPaid())->toBeTrue()
        ->and($job->reviewRequest()->exists())->toBeFalse();

    // Mark work complete (the job was prepaid) opens the call-first queue.
    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('markWorkComplete')
        ->assertHasNoActionErrors();

    $job->refresh();
    expect($job->isWorkComplete())->toBeTrue()
        ->and($job->reviewRequest()->exists())->toBeTrue()
        ->and($job->reviewRequest->status)->toBe(\App\Models\ReviewRequest::STATUS_AWAITING_CALL)
        ->and($job->reviewRequest->sentiment)->toBeNull();
});

it('opens no review request on Record payment when the work is already complete', function () {
    $job = scheduledJob($this);
    $job->update(['work_completed_at' => now()]); // normal flow: completed, then paid

    Livewire::test(ViewJob::class, ['record' => $job->refresh()->getRouteKey()])
        ->callAction('recordPayment', ['amount' => 450, 'payment_method' => PaymentMethod::Zelle->value])
        ->assertHasNoActionErrors();

    expect($job->refresh()->reviewRequest()->exists())->toBeFalse();
});

it('re-confirming the same preference never double-texts', function () {
    $job = scheduledJob($this);

    $this->lifecycle->confirmPaymentPreference($job, PaymentMethod::Zelle, $this->actor);
    $this->lifecycle->confirmPaymentPreference($job, PaymentMethod::Zelle, $this->actor);

    expect($job->scheduledMessages()->where('template_key', TemplateKey::JobComplete->value)->count())->toBe(1);
});
