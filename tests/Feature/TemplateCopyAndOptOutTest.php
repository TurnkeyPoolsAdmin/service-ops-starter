<?php

use App\Enums\Language;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\TemplateKey;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\MessageTemplate;
use App\Services\JobLifecycle;
use App\Services\Messaging\OutboxService;
use App\Services\Messaging\TemplateRenderer;
use App\Support\Timezone;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WarehouseSeeder;

beforeEach(function () {
    $this->seed([
        RoleSeeder::class,
        EntitySeeder::class,
        WarehouseSeeder::class,
        MessageTemplateSeeder::class,
        SettingSeeder::class,
    ]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'copy-op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->lifecycle = app(JobLifecycle::class);
});

function copyIntakeData(array $extra = []): array
{
    return $extra + [
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185559911',
        'first_name' => 'Karen',
        'last_name' => 'Ruiz',
        'vehicle_year' => '2019', 'vehicle_make' => 'Honda', 'vehicle_model' => 'Civic',
        'glass_position' => 'windshield',
        'quote_amount_cents' => 45000,
        'requested_daytime_raw' => 'Thursday',
        'intake_raw_text' => 'raw', 'parse_status' => 'clean',
    ];
}

// ---- Merge variables ----

it('has no template body referencing a merge var the renderer cannot resolve', function () {
    // The bug this guards: review_request_1 shipped with {technician_name},
    // which the renderer blanks, so every customer read "One small favor:
    // worked on your vehicle today".
    $known = array_merge(TemplateRenderer::SUPPORTED_VARS, ['date_time', 'warranty_date_time']);

    foreach (MessageTemplate::all() as $template) {
        preg_match_all('/\{(\w+)\}/', $template->body, $matches);
        $unknown = array_diff(array_unique($matches[1]), $known);

        expect($unknown)->toBe(
            [], "template [{$template->key}/{$template->language->value}] uses unknown var(s): ".implode(',', $unknown)
        );
    }
});

it('renders the technician name into the first review request', function () {
    $tech = Employee::create([
        'name' => 'Sonia', 'email' => 'sonia@test.local', 'active' => true, 'job_functions' => ['technician'],
    ]);

    $job = $this->lifecycle->createFromIntake(copyIntakeData(), $this->actor);
    $job->update(['technician_id' => $tech->id]);
    $job->entity->update(['google_review_url' => 'https://g.page/r/abc']);

    $body = app(TemplateRenderer::class)->renderForJob(TemplateKey::ReviewRequest1, $job->refresh());

    expect($body)->toContain('Sonia');
});

// ---- Opt-out placement ----

it('offers an opt-out on every review template and no other', function () {
    foreach (MessageTemplate::all() as $template) {
        $offersOptOut = stripos($template->body, 'STOP') !== false;
        $isReview = TemplateKey::from($template->key)->messageType() === MessageType::Review;

        expect($offersOptOut)->toBe(
            $isReview,
            "template [{$template->key}/{$template->language->value}] "
                .($isReview ? 'must offer an opt-out' : 'must NOT offer an opt-out')
        );
    }
});

it('keeps transactional reminders free of opt-out language', function () {
    // A customer opting out loses ALL automated texts (OutboxService gates on
    // do_not_text), so a reminder must never invite a STOP.
    foreach ([TemplateKey::ReminderNightBefore, TemplateKey::ReminderMorningOf] as $key) {
        expect($key->requiresOptOutLanguage())->toBeFalse();

        foreach (MessageTemplate::where('key', $key->value)->get() as $row) {
            expect(stripos($row->body, 'STOP'))->toBeFalse();
        }
    }
});

it('requires an opt-out on all six review drip templates', function () {
    $reviewKeys = [
        TemplateKey::ReviewRequest1, TemplateKey::ReviewRequest2, TemplateKey::ReviewAfterCall,
        TemplateKey::ReviewVoicemail, TemplateKey::ReviewFinal, TemplateKey::ReviewThankYou,
    ];

    foreach ($reviewKeys as $key) {
        expect($key->requiresOptOutLanguage())->toBeTrue("[{$key->value}] should require opt-out language");
    }
});

// ---- Language switch reaches messages already queued ----

it('rewrites already-queued texts when a customer is switched to Spanish', function () {
    $job = $this->lifecycle->createFromIntake(copyIntakeData(), $this->actor);

    // Queue a reminder while the customer still reads as English — the body is
    // rendered NOW, days before it sends.
    $sendAt = Timezone::now()->addDays(2)->utc();
    $job->update(['scheduled_at' => Timezone::now()->addDays(3)->utc()]);

    $queued = app(OutboxService::class)->queueForJob(
        $job->refresh(), TemplateKey::ReminderNightBefore, $sendAt, $this->actor->id,
    );

    expect($queued->rendered_body)->toContain('Reminder from')
        ->and($queued->rendered_body)->not->toContain('Recordatorio');

    $rewritten = $this->lifecycle->setCustomerLanguage($job->customer, Language::Spanish);

    expect($rewritten)->toBe(1)
        ->and($queued->refresh()->rendered_body)->toContain('Recordatorio')
        ->and($queued->rendered_body)->not->toContain('Reminder from');
});

it('leaves already-sent texts untouched when the language changes', function () {
    $job = $this->lifecycle->createFromIntake(copyIntakeData(), $this->actor);
    $job->update(['scheduled_at' => Timezone::now()->addDays(3)->utc()]);

    $sent = app(OutboxService::class)->queueForJob(
        $job->refresh(), TemplateKey::ReminderNightBefore, Timezone::now()->addDays(2)->utc(), $this->actor->id,
    );
    $sent->update(['status' => MessageStatus::Sent->value, 'sent_at' => now()]);
    $original = $sent->rendered_body;

    $this->lifecycle->setCustomerLanguage($job->customer, Language::Spanish);

    expect($sent->refresh()->rendered_body)->toBe($original);
});

it('reports no rewrites when the language is unchanged', function () {
    $job = $this->lifecycle->createFromIntake(copyIntakeData(), $this->actor);

    expect($this->lifecycle->setCustomerLanguage($job->customer, Language::English))->toBe(0);
});
