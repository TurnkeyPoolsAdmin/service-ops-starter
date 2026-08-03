<?php

use App\Enums\Audience;
use App\Enums\Language;
use App\Enums\MessageType;
use App\Enums\TemplateKey;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Messaging\TemplateRenderException;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class]);
    $this->renderer = app(TemplateRenderer::class);

    $customer = Customer::create(['phone_e164' => '+18185551234', 'first_name' => 'Maria']);
    $this->job = Job::create([
        'job_number' => 'LA-2026-98001',
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customer->id,
        'vehicle_make' => 'Honda',
        'vehicle_model' => 'Civic',
        'glass_position' => 'Windshield',
        'city' => 'Van Nuys',
    ]);
});

it('classifies the AI scheduling keys correctly', function () {
    expect(TemplateKey::TechAiSlotOffer->audience())->toBe(Audience::Tech)
        ->and(TemplateKey::TechAiSlotReiterate->audience())->toBe(Audience::Tech)
        ->and(TemplateKey::TechAiSlotConfirm->audience())->toBe(Audience::Tech)
        ->and(TemplateKey::TechAiSlotConfirm->messageType())->toBe(MessageType::TechOutreach)
        ->and(TemplateKey::TechAiSlotConfirm->requiredVars())->toBe(['slot_phrase'])
        ->and(TemplateKey::TechAiWarehouseAsk->audience())->toBe(Audience::Tech)
        ->and(TemplateKey::TechAiWarehouseAsk->requiredVars())->toBe([])
        ->and(TemplateKey::TechAiWarehouseSet->requiredVars())->toBe(['warehouse'])
        ->and(TemplateKey::TechJobConfirmation->audience())->toBe(Audience::Tech)
        ->and(TemplateKey::TechAiFilled->audience())->toBe(Audience::Tech)
        ->and(TemplateKey::CustomerAiTimeCheck->audience())->toBe(Audience::Customer)
        ->and(TemplateKey::TechAiSlotOffer->messageType())->toBe(MessageType::TechOutreach)
        ->and(TemplateKey::TechJobConfirmation->messageType())->toBe(MessageType::TechOutreach)
        ->and(TemplateKey::CustomerAiTimeCheck->messageType())->toBe(MessageType::Confirmation)
        ->and(TemplateKey::TechAiSlotOffer->preferredProvider())->toBe('openphone')
        ->and(TemplateKey::CustomerAiTimeCheck->preferredProvider())->toBe('openphone')
        ->and(TemplateKey::TechAiSlotOffer->requiresOptOutLanguage())->toBeFalse()
        ->and(TemplateKey::CustomerAiTimeCheck->requiresOptOutLanguage())->toBeFalse();
});

it('requires slot_phrase / constraint_phrase before queuing', function () {
    expect(TemplateKey::TechAiSlotOffer->requiredVars())->toBe(['slot_phrase'])
        ->and(TemplateKey::TechAiSlotReiterate->requiredVars())->toBe(['constraint_phrase'])
        // The booking now closes on the shared job card, which is built
        // around the scheduled time rather than a passed-in slot phrase.
        ->and(TemplateKey::TechJobConfirmation->requiredVars())->toBe(['scheduled_date_time'])
        ->and(TemplateKey::TechAiFilled->requiredVars())->toBe([])
        ->and(TemplateKey::CustomerAiTimeCheck->requiredVars())->toBe(['slot_phrase']);

    expect(fn () => $this->renderer->renderForJob(TemplateKey::TechAiSlotOffer, $this->job))
        ->toThrow(TemplateRenderException::class);
});

it('renders the tech slot-offer text with the load note folded in, English and Spanish', function () {
    $body = $this->renderer->renderForJob(TemplateKey::TechAiSlotOffer, $this->job, extraVars: [
        'slot_phrase' => 'Tuesday morning (8am-12pm)',
        'load_note' => 'You already have one job that morning. ',
    ], language: Language::English);

    expect($body)->toContain('Tuesday morning (8am-12pm)')
        ->toContain('You already have one job that morning. Can you take it?')
        ->toContain('Honda Civic')
        ->toContain('Van Nuys');

    $bodyEs = $this->renderer->renderForJob(TemplateKey::TechAiSlotOffer, $this->job, extraVars: [
        'slot_phrase' => 'martes por la mañana (8am-12pm)',
        'load_note' => '',
    ], language: Language::Spanish);

    expect($bodyEs)->toContain('¿Lo puedes hacer?')
        ->not->toContain('{load_note}');
});

it('renders the reiterate text with the constraint phrase', function () {
    $body = $this->renderer->renderForJob(TemplateKey::TechAiSlotReiterate, $this->job, extraVars: [
        'constraint_phrase' => 'in the morning',
    ]);

    expect($body)->toContain('the customer needs it in the morning');
});

it('renders the customer retarget check with the recipient first name', function () {
    $body = $this->renderer->renderForJob(TemplateKey::CustomerAiTimeCheck, $this->job, extraVars: [
        'slot_phrase' => 'Wednesday afternoon',
    ], language: Language::English);

    expect($body)->toContain('Hi Maria')->toContain('Wednesday afternoon');
});

it('seeded an English and Spanish row for every new key', function () {
    foreach ([
        TemplateKey::TechAiSlotOffer, TemplateKey::TechAiSlotReiterate,
        TemplateKey::TechAiSlotConfirm, TemplateKey::TechJobConfirmation,
        TemplateKey::TechAiWarehouseAsk, TemplateKey::TechAiWarehouseSet,
        TemplateKey::TechAiFilled, TemplateKey::CustomerAiTimeCheck,
    ] as $key) {
        expect($this->renderer->resolveTemplate($key, Language::English)->language)->toBe(Language::English);
        expect($this->renderer->resolveTemplate($key, Language::Spanish)->language)->toBe(Language::Spanish);
    }
});

it('the AI scheduling opt-in toggle exists and defaults off', function () {
    $tech = Employee::create([
        'name' => 'Jose', 'entity_id' => Entity::where('code', 'LA')->value('id'),
        'job_functions' => ['technician'], 'active' => true,
    ]);

    expect($tech->fresh()->ai_scheduling_enabled)->toBeFalse();
});
