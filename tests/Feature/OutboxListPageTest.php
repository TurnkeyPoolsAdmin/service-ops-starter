<?php

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\TemplateKey;
use App\Filament\Resources\ScheduledMessages\Pages\ListScheduledMessages;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\ScheduledMessage;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->dispatch = App\Models\Employee::create([
        'name' => 'Dispatch', 'email' => 'd@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->dispatch->assignRole('dispatch');
});

function makeMessage(string $status, string $body): ScheduledMessage
{
    $entity = Entity::first();
    $customer = Customer::create(['phone_e164' => '+1818555'.random_int(1000, 9999), 'first_name' => 'C']);

    return ScheduledMessage::create([
        'customer_id' => $customer->id,
        'entity_id' => $entity->id,
        'template_key' => TemplateKey::WelcomeGeneric->value,
        'message_type' => MessageType::Welcome->value,
        'to_phone_e164' => $customer->phone_e164,
        'from_phone_e164' => '+13235550134',
        'rendered_body' => $body,
        'status' => $status,
        'idempotency_key' => 'test:'.Str::uuid(),
    ]);
}

it('renders the outbox showing only pending messages and hiding sent ones', function () {
    makeMessage(MessageStatus::Queued->value, 'PENDING_QUEUED_MSG');
    makeMessage(MessageStatus::Failed->value, 'PENDING_FAILED_MSG');
    makeMessage(MessageStatus::Sent->value, 'ALREADY_SENT_MSG');
    makeMessage(MessageStatus::Skipped->value, 'WAS_SKIPPED_MSG');

    $this->actingAs($this->dispatch)
        ->get(ListScheduledMessages::getUrl())
        ->assertSuccessful()
        ->assertSee('PENDING_QUEUED_MSG')
        ->assertSee('PENDING_FAILED_MSG')
        ->assertDontSee('ALREADY_SENT_MSG')
        ->assertDontSee('WAS_SKIPPED_MSG');
});
