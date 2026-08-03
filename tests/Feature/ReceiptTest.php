<?php

use App\Enums\MessageType;
use App\Enums\PaymentMethod;
use App\Enums\TemplateKey;
use App\Filament\Resources\Jobs\Pages\ViewJob;
use App\Jobs\SendSmsJob;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\MessageTemplate;
use App\Services\JobLifecycle;
use App\Services\Receipts\ReceiptPdfService;
use App\Support\GsmText;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
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

function receiptJob($test): Job
{
    $job = $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Alan',
        'quote_amount_cents' => 45000,
        'vehicle_year' => '2020', 'vehicle_make' => 'Toyota', 'vehicle_model' => 'Camry',
    ], $test->actor);
    $test->lifecycle->assignTechnician($job, $test->tech->id, $test->actor);

    return $job->refresh();
}

// ---- Required var / render gate ----

it('refuses to queue a receipt with no receipt link (required var)', function () {
    $job = receiptJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);

    // Called the way OutboxService always is elsewhere in the app: no
    // {receipt_link} supplied, so the required-var check must block it, even
    // though the job is fully paid.
    $msg = $this->lifecycle->sendManualMessage($job->refresh(), TemplateKey::Receipt, $this->actor);

    expect($msg)->toBeNull();
});

it('sends a receipt whose body carries a valid signed PDF link', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-06 15:00', 'America/Los_Angeles'));
    $job = receiptJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);
    $job->refresh();

    $link = app(ReceiptPdfService::class)->signedUrlFor($job);
    $msg = $this->lifecycle->sendManualMessage($job, TemplateKey::Receipt, $this->actor, ['receipt_link' => $link]);

    expect($msg)->not->toBeNull()
        ->and($msg->message_type)->toBe(MessageType::Manual)
        ->and($msg->rendered_body)->toContain($link)
        ->and($msg->rendered_body)->toContain('/receipts/'.$job->id);
    Queue::assertPushed(SendSmsJob::class);

    expect(URL::hasValidSignature(Request::create($link)))->toBeTrue();
});

it('is repeatable — a re-send makes a fresh row', function () {
    $job = receiptJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Cash, null, null, $this->actor);
    $job->refresh();

    $vars = ['receipt_link' => app(ReceiptPdfService::class)->signedUrlFor($job)];
    $this->lifecycle->sendManualMessage($job, TemplateKey::Receipt, $this->actor, $vars);
    $this->lifecycle->sendManualMessage($job, TemplateKey::Receipt, $this->actor, $vars);

    expect($job->scheduledMessages()->where('template_key', TemplateKey::Receipt->value)->count())->toBe(2);
});

it('never texts a receipt to an opted-out customer', function () {
    $job = receiptJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);
    $job->customer->update(['do_not_text' => true]);
    $job->refresh();

    $vars = ['receipt_link' => app(ReceiptPdfService::class)->signedUrlFor($job)];
    expect($this->lifecycle->sendManualMessage($job, TemplateKey::Receipt, $this->actor, $vars))->toBeNull();
});

// ---- Job action ----

it('the Send receipt action on the job view sends a receipt with a link', function () {
    $job = receiptJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction('sendReceipt');

    $message = $job->scheduledMessages()->where('template_key', TemplateKey::Receipt->value)->first();

    expect($message)->not->toBeNull()
        ->and($message->rendered_body)->toContain('/receipts/'.$job->id)
        ->and($message->rendered_body)->toContain('signature=');
});

it('hides the Send receipt action until the job is paid', function () {
    $job = receiptJob($this);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->assertActionHidden('sendReceipt');
});

// ---- Signed PDF route ----

it('serves a PDF for a paid job on a validly signed link', function () {
    $job = receiptJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);
    $job->refresh();

    $link = app(ReceiptPdfService::class)->signedUrlFor($job);

    $response = $this->get($link);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect((string) $response->getContent())->toStartWith('%PDF');
});

it('rejects a tampered signature with 403', function () {
    $job = receiptJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);
    $job->refresh();

    $link = app(ReceiptPdfService::class)->signedUrlFor($job);
    $tampered = $link.'x';

    $this->get($tampered)->assertForbidden();
});

it('rejects an unsigned URL with 403', function () {
    $job = receiptJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);
    $job->refresh();

    $this->get(route('receipts.show', ['job' => $job->id]))->assertForbidden();
});

it('rejects an expired signature with 403', function () {
    $job = receiptJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);
    $job->refresh();

    Carbon::setTestNow(now());
    $link = URL::temporarySignedRoute('receipts.show', now()->addDays(30), ['job' => $job->id]);

    Carbon::setTestNow(now()->addDays(31));

    $this->get($link)->assertForbidden();
});

it('404s an unpaid job even with a validly signed link', function () {
    $job = receiptJob($this);

    $link = URL::temporarySignedRoute('receipts.show', now()->addDays(30), ['job' => $job->id]);

    $this->get($link)->assertNotFound();
});

it('404s a receipt link for a job that no longer exists', function () {
    $link = URL::temporarySignedRoute('receipts.show', now()->addDays(30), ['job' => 999999]);

    $this->get($link)->assertNotFound();
});

// ---- PDF content ----

it('renders every payment line and the balance due for a partially paid job', function () {
    $job = receiptJob($this);
    $this->lifecycle->recordPayment($job, 20000, PaymentMethod::Zelle, null, null, $this->actor);
    $job->refresh();
    $this->lifecycle->recordPayment($job, 10000, PaymentMethod::Cash, null, null, $this->actor);
    $job->refresh();

    $html = app(ReceiptPdfService::class)->html($job);

    expect($html)
        ->toContain('$200.00')
        ->toContain('Zelle')
        ->toContain('$100.00')
        ->toContain('Cash')
        ->toContain('$150.00') // balance due: 450 - 300
        ->toContain('Balance due')
        ->not->toContain('PAID IN FULL');
});

it('shows PAID IN FULL and no balance due once fully paid', function () {
    $job = receiptJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);
    $job->refresh();

    $html = app(ReceiptPdfService::class)->html($job);

    expect($html)
        ->toContain('PAID IN FULL')
        ->toContain('$450.00')
        ->not->toContain('Balance due');
});

it('includes entity branding, customer, and vehicle in the PDF', function () {
    $job = receiptJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);
    $job->refresh();

    $html = app(ReceiptPdfService::class)->html($job);

    expect($html)
        ->toContain($job->entity->name)
        ->toContain('Alan')
        ->toContain('2020 Toyota Camry');
});

it('renders the entity address on the letterhead when set, and omits it when blank', function () {
    $job = receiptJob($this);
    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);
    $job->refresh();

    $html = app(ReceiptPdfService::class)->html($job);
    expect($html)->not->toContain('123 Main St');

    $job->entity->update(['address' => "123 Main St\nLos Angeles, CA 90001"]);
    $html = app(ReceiptPdfService::class)->html($job->refresh());

    expect($html)
        ->toContain('123 Main St')
        ->toContain('Los Angeles, CA 90001');
});

// ---- Template copy validation ----

it('has an ASCII-only receipt template body in both languages', function () {
    $rows = MessageTemplate::where('key', TemplateKey::Receipt->value)->get();

    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect(GsmText::offendingChars($row->body))->toBe([], "receipt/{$row->language->value} has smart punctuation");
    }
});

it('requires {receipt_link} and no longer requires {amount_paid}', function () {
    expect(TemplateKey::Receipt->requiredVars())->toBe(['receipt_link']);
});
