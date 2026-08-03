<?php

use App\Enums\Audience;
use App\Enums\Language;
use App\Enums\TemplateKey;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\MessageTemplate;
use App\Services\JobLifecycle;
use App\Services\Messaging\OutboxService;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateRenderException;
use App\Support\Timezone;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->seed([
        RoleSeeder::class,
        EntitySeeder::class,
        WarehouseSeeder::class,
        MessageTemplateSeeder::class,
        SettingSeeder::class,
    ]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'lang-op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->lifecycle = app(JobLifecycle::class);
});

/** Minimal intake payload; named apart from JobLifecycleTest's global langIntakeData(). */
function langIntakeData(array $extra = []): array
{
    return $extra + [
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Alan',
        'last_name' => 'Fairview',
        'vehicle_year' => '2022', 'vehicle_make' => 'Tesla', 'vehicle_model' => 'Model Y',
        'glass_position' => 'windshield',
        'quote_amount_cents' => 95000,
        'requested_daytime_raw' => 'Thursday',
        'intake_raw_text' => 'raw', 'parse_status' => 'clean',
    ];
}

it('seeds an English and a Spanish row for every template key it defines', function () {
    $byKey = collect(MessageTemplateSeeder::definitions())->groupBy('key');

    foreach ($byKey as $key => $rows) {
        expect($rows->pluck('language')->sort()->values()->all())
            ->toBe(['en', 'es'], "template [{$key}] is missing a translation");
    }

    // Every seeded key is a real lifecycle event, and the digests (rendered in
    // PHP, not from a row) are deliberately absent.
    expect($byKey->keys()->every(fn ($k) => TemplateKey::tryFrom($k) !== null))->toBeTrue()
        ->and($byKey->has(TemplateKey::TechReminderNightBefore->value))->toBeFalse();
});

it('derives audience from the key rather than storing it', function () {
    expect(TemplateKey::TechJobAvailability->audience())->toBe(Audience::Tech)
        ->and(TemplateKey::TechJobCanceled->audience())->toBe(Audience::Tech)
        ->and(TemplateKey::JobComplete->audience())->toBe(Audience::Customer);

    expect(TemplateKey::valuesFor(Audience::Tech))
        ->toContain(TemplateKey::TechJobCanceled->value)
        ->not->toContain(TemplateKey::JobComplete->value);
});

it('renders a customer text from the row matching the customer preferred language', function () {
    $job = $this->lifecycle->createFromIntake(langIntakeData(), $this->actor);
    $job->customer->update(['preferred_language' => Language::Spanish]);

    $body = app(TemplateRenderer::class)
        ->renderForJob(TemplateKey::WelcomeGeneric, $job->refresh(), language: Language::Spanish);

    expect($body)->toContain('gracias por elegir')->not->toContain('thanks for choosing');
});

it('falls back to the English row when the requested translation is missing', function () {
    MessageTemplate::query()
        ->forKey(TemplateKey::WelcomeGeneric)->where('language', 'es')->delete();

    $job = $this->lifecycle->createFromIntake(langIntakeData(), $this->actor);

    $body = app(TemplateRenderer::class)
        ->renderForJob(TemplateKey::WelcomeGeneric, $job, language: Language::Spanish);

    expect($body)->toContain('thanks for choosing');
});

it('falls back to the English row when the translation is switched off', function () {
    MessageTemplate::query()
        ->forKey(TemplateKey::WelcomeGeneric)->where('language', 'es')->update(['active' => false]);

    $job = $this->lifecycle->createFromIntake(langIntakeData(), $this->actor);

    expect(app(TemplateRenderer::class)->renderForJob(TemplateKey::WelcomeGeneric, $job, language: Language::Spanish))
        ->toContain('thanks for choosing');
});

it('refuses to render when neither the translation nor English can send', function () {
    MessageTemplate::query()->forKey(TemplateKey::WelcomeGeneric)->update(['active' => false]);

    $job = $this->lifecycle->createFromIntake(langIntakeData(), $this->actor);

    expect(fn () => app(TemplateRenderer::class)
        ->renderForJob(TemplateKey::WelcomeGeneric, $job, language: Language::Spanish))
        ->toThrow(TemplateRenderException::class);
});

it('phrases the appointment date in the language of the row it resolved', function () {
    $job = $this->lifecycle->createFromIntake(langIntakeData(), $this->actor);
    $tech = Employee::create([
        'name' => 'Tech', 'email' => 'lang-tech@test.local', 'can_login' => false,
        'active' => true, 'password' => bcrypt('x'), 'job_functions' => ['technician'],
        'phone' => '+18185551234',
    ]);
    // A Monday, so the weekday name is unambiguous in both languages.
    $this->lifecycle->schedule(
        $job, Timezone::now()->next('Monday')->setTime(8, 0)->utc(), $this->actor, technicianId: $tech->id,
    );

    $renderer = app(TemplateRenderer::class);
    $es = $renderer->varsForJob($job->refresh(), null, Language::Spanish);
    $en = $renderer->varsForJob($job, null, Language::English);

    expect($es['scheduled_date_time'])->toContain('lunes')->toContain('entre 8am y 10am')
        ->and($en['scheduled_date_time'])->toContain('Monday')->toContain('between 8am-10am');
});

it('queues a customer text in the customer language and a tech text in the tech language', function () {
    $job = $this->lifecycle->createFromIntake(langIntakeData(), $this->actor);
    $job->customer->update(['preferred_language' => Language::Spanish]);

    $customerMsg = app(OutboxService::class)
        ->queueForJob($job->refresh(), TemplateKey::WelcomeGeneric, null, $this->actor->id);
    expect($customerMsg->rendered_body)->toContain('gracias por elegir');

    // Techs default to Spanish; this one reads English.
    $tech = Employee::create([
        'name' => 'Eng Tech', 'email' => 'eng-tech@test.local', 'can_login' => false,
        'active' => true, 'password' => bcrypt('x'), 'job_functions' => ['technician'],
        'phone' => '+18185557777', 'preferred_language' => 'en',
    ]);

    $techMsg = app(OutboxService::class)
        ->queueJobMessageForTech($job, $tech, TemplateKey::TechJobAvailability, $this->actor->id);

    expect($techMsg->rendered_body)->toContain('I have a new job')
        ->and($techMsg->rendered_body)->not->toContain('trabajo nuevo');
});

it('lets intake mark a Spanish speaker without ever resetting one back to English', function () {
    $job = $this->lifecycle->createFromIntake(
        langIntakeData(['preferred_language' => Language::Spanish->value]), $this->actor,
    );
    expect($job->customer->refresh()->isSpanishSpeaker())->toBeTrue();

    // A second job for the same phone, toggle left off → null → preference kept.
    $this->lifecycle->createFromIntake(langIntakeData(), $this->actor);

    expect(Customer::whereKey($job->customer_id)->sole()->isSpanishSpeaker())->toBeTrue();
});

it('keeps one row per key and language', function () {
    expect(fn () => MessageTemplate::create([
        'key' => TemplateKey::WelcomeGeneric->value,
        'language' => Language::English->value,
        'name' => 'dupe', 'body' => 'x', 'channel' => 'sms', 'active' => true,
    ]))->toThrow(QueryException::class);
});
