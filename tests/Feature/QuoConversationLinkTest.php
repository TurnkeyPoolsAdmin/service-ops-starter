<?php

use App\Enums\TemplateKey;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\MessageTemplate;
use App\Services\JobLifecycle;
use App\Services\Messaging\QuoConversationLinker;
use App\Services\Messaging\TemplateRenderer;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->actor = Employee::create([
        'name' => 'Nico', 'email' => 'nico@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ])->assignRole('super_admin');

    $this->lifecycle = app(JobLifecycle::class);
});

function linkJob($test)
{
    return $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234', 'first_name' => 'Susan', 'quote_amount_cents' => 50000,
    ], $test->actor);
}

it('builds a Quo inbox url from the phoneNumberId + conversationId', function () {
    $url = app(QuoConversationLinker::class)->urlFor('PNK3QamaPL', 'CN59e86035159042c090ac53185379c6eb');

    expect($url)->toBe('https://my.quo.com/inbox/PNK3QamaPL/c/CN59e86035159042c090ac53185379c6eb');
});

it('returns null when either id is missing', function () {
    $linker = app(QuoConversationLinker::class);

    expect($linker->urlFor(null, 'CN1'))->toBeNull()
        ->and($linker->urlFor('PN1', null))->toBeNull();
});

it('captures the conversation link onto the customer from a message object', function () {
    $customer = Customer::create(['phone_e164' => '+18185551234', 'first_name' => 'Susan']);

    app(QuoConversationLinker::class)->captureFromObject($customer, [
        'phoneNumberId' => 'PNK3QamaPL',
        'conversationId' => 'CN59e86035159042c090ac53185379c6eb',
    ]);

    expect($customer->refresh()->quo_conversation_url)
        ->toBe('https://my.quo.com/inbox/PNK3QamaPL/c/CN59e86035159042c090ac53185379c6eb');
});

it('is a no-op for a message object without conversation ids (e.g. Podium)', function () {
    $customer = Customer::create(['phone_e164' => '+18185551234', 'first_name' => 'Susan']);

    app(QuoConversationLinker::class)->captureFromObject($customer, ['uid' => 'abc', 'body' => 'hi']);

    expect($customer->refresh()->quo_conversation_url)->toBeNull();
});

it('renders {dispatch} as the acting dispatcher name, falling back to the job creator', function () {
    $job = linkJob($this);
    MessageTemplate::where('key', TemplateKey::WelcomeGeneric->value)
        ->update(['body' => 'Hi {first_name}, this is {dispatch}.']);

    $renderer = app(TemplateRenderer::class);

    // Explicit dispatcher wins.
    expect($renderer->renderForJob(TemplateKey::WelcomeGeneric, $job, 'Marcel'))
        ->toBe('Hi Susan, this is Marcel.');

    // Falls back to whoever created the job (Nico) when none is given.
    expect($renderer->renderForJob(TemplateKey::WelcomeGeneric, $job))
        ->toBe('Hi Susan, this is Nico.');
});
