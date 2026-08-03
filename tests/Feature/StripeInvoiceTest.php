<?php

use App\Enums\MessageStatus;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\ScheduledMessage;
use App\Models\Setting;
use App\Services\JobLifecycle;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    Setting::put('stripe_secret_key', 'sk_test_abc', sensitive: true);
    Setting::put('sending_phone_e164', '+18185550000');

    $this->lifecycle = app(JobLifecycle::class);
});

function stripeJob(): App\Models\Job
{
    return app(JobLifecycle::class)->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Cara',
        'vehicle_year' => '2020', 'vehicle_make' => 'Honda', 'vehicle_model' => 'Civic',
        'quote_amount_cents' => 30000, // $300
        'intake_raw_text' => 'raw',
    ], Employee::first());
}

function fakeStripe(): void
{
    Http::fake([
        'https://api.stripe.com/v1/payment_links' => Http::response([
            'id' => 'plink_1',
            'url' => 'https://buy.stripe.com/test_abc',
            'active' => true,
        ]),
    ]);
}

it('creates a quote+3% payment link and texts the URL', function () {
    fakeStripe();
    $job = stripeJob();

    $message = $this->lifecycle->createAndTextStripeInvoice($job, $this->actor);
    $job->refresh();

    // $300 + 3% = $309.00, stored as the surcharged total.
    expect($job->stripe_invoice_amount_cents)->toBe(30900)
        ->and($job->stripe_invoice_id)->toBe('plink_1')
        ->and($job->stripe_invoice_url)->toBe('https://buy.stripe.com/test_abc')
        ->and($job->stripe_invoice_status)->toBe('open')
        ->and($job->stripe_customer_id)->toBeNull();

    // The link text was queued to the customer with the URL + total merged in.
    expect($message)->not->toBeNull()
        ->and($message->template_key->value)->toBe('payment_link')
        ->and($message->status->value)->toBe(MessageStatus::Queued->value)
        ->and($message->rendered_body)->toContain('https://buy.stripe.com/test_abc')
        ->and($message->rendered_body)->toContain('$309.00');

    expect(Activity::where('subject_id', $job->id)->pluck('event'))
        ->toContain('stripe_invoice_created')
        ->toContain('stripe_invoice_texted');
});

it('sends exactly one line item at the surcharged amount via Payment Links', function () {
    fakeStripe();
    $job = stripeJob();

    $this->lifecycle->createAndTextStripeInvoice($job, $this->actor);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v1/payment_links')
            && $request['line_items[0][price_data][unit_amount]'] == 30900
            && $request['line_items[0][price_data][currency]'] === 'usd'
            && $request['line_items[0][quantity]'] == 1;
    });

    // Must not use the Invoicing API (extra 0.4% fee).
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/invoices'));
});

it('refuses to build a payment link without a quote', function () {
    fakeStripe();
    $job = stripeJob();
    $job->update(['quote_amount_cents' => null]);

    expect(fn () => $this->lifecycle->createAndTextStripeInvoice($job, $this->actor))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
});

it('re-sends the existing link without creating a second payment link', function () {
    fakeStripe();
    $job = stripeJob();
    $this->lifecycle->createAndTextStripeInvoice($job, $this->actor);
    $job->refresh();

    Http::fake(); // any further Stripe call would now be unexpected
    $again = $this->lifecycle->resendStripeInvoiceLink($job, $this->actor);

    expect($again)->not->toBeNull()
        ->and(ScheduledMessage::where('template_key', 'payment_link')->count())->toBe(2);
    Http::assertNothingSent();
});
