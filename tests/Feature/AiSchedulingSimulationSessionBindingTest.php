<?php

use App\Enums\JobStatus;
use App\Models\{AiSchedulingAttempt, AiSchedulingSession, AiSchedulingTurn, Customer, Employee, Entity, InboundMessage, Job};
use App\Services\AiScheduling\{AiSchedulingService, LlmClassifier, ReplyIntent, SchedulingConstraint};
use Carbon\Carbon;
use Database\Seeders\{EntitySeeder, MessageTemplateSeeder, RoleSeeder, SettingSeeder};
use Illuminate\Support\Facades\{DB, Http, Mail, Notification, Queue};

class SimulationBindingClassifier implements LlmClassifier
{
    public function classifyTechReply(string $reply, string $constraintDescription, string $ourLastMessage, array $priorTurns, string $today): ?ReplyIntent
    {
        return null;
    }

    public function classifyCustomerReply(string $reply, string $constraintDescription, string $today): ?ReplyIntent
    {
        return null;
    }

    public function classifyWarehouseReply(string $reply, array $warehouseNames): ?int
    {
        return null;
    }
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake(); Mail::fake(); Notification::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);
    Carbon::setTestNow(Carbon::parse('2026-07-20 09:00', 'America/Los_Angeles'));
    $this->app->instance(LlmClassifier::class, new SimulationBindingClassifier);
    $this->entityId = Entity::where('code', 'LA')->value('id');
    $this->admin = Employee::create([
        'name'=>'Binding Admin', 'email'=>'binding-admin@example.test', 'entity_id'=>$this->entityId,
        'can_login'=>true, 'active'=>true, 'password'=>bcrypt('dummy-password'),
        'job_functions'=>['office'],
    ])->assignRole('super_admin');
    $this->tech = Employee::create([
        'name'=>'Binding Tech', 'email'=>'binding-tech@example.test', 'phone'=>'+12025550123',
        'entity_id'=>$this->entityId, 'job_functions'=>['technician'], 'active'=>true,
        'priority'=>1, 'ai_scheduling_enabled'=>true,
    ]);
    $this->otherTech = Employee::create([
        'name'=>'Other Binding Tech', 'email'=>'other-binding-tech@example.test',
        'phone'=>'+12025550124', 'entity_id'=>$this->entityId,
        'job_functions'=>['technician'], 'active'=>true, 'priority'=>2,
        'ai_scheduling_enabled'=>true,
    ]);
    $this->service = app(AiSchedulingService::class);
});
afterEach(fn () => Carbon::setTestNow());

function bindingJob($test, int $number, ?Customer $customer = null): Job
{
    $customer ??= Customer::create(['phone_e164'=>sprintf('+1202555%04d', $number), 'first_name'=>'Dummy']);
    return Job::create([
        'job_number'=>sprintf('LA-2026-9%04d', $number), 'entity_id'=>$test->entityId,
        'customer_id'=>$customer->id, 'status'=>JobStatus::PendingSchedule->value,
        'vehicle_make'=>'Honda', 'vehicle_model'=>'Civic', 'glass_position'=>'Windshield',
        'city'=>'Van Nuys',
    ]);
}

function bindingLiveSession($test, Job $job, string $techMode, string $customerMode): AiSchedulingSession
{
    return AiSchedulingSession::create([
        'job_id'=>$job->id, 'status'=>AiSchedulingSession::STATUS_RUNNING,
        'constraint'=>SchedulingConstraint::window('2026-07-22', 'morning')->toArray(),
        'original_constraint'=>SchedulingConstraint::window('2026-07-22', 'morning')->toArray(),
        'tech_mode'=>$techMode, 'customer_mode'=>$customerMode,
        'created_by'=>$test->admin->id,
    ]);
}

function bindingAttempt(AiSchedulingSession $session, Employee $tech): AiSchedulingAttempt
{
    return AiSchedulingAttempt::create([
        'session_id'=>$session->id, 'employee_id'=>$tech->id, 'position'=>1,
        'status'=>AiSchedulingAttempt::STATUS_ASKED,
    ]);
}

it('routes a simulated tech reply to the selected session, including a superseded attempt', function () {
    $selected = bindingLiveSession($this, bindingJob($this, 1), AiSchedulingSession::MODE_SIMULATE, AiSchedulingSession::MODE_OFF);
    $selectedAttempt = bindingAttempt($selected, $this->tech);
    // The selected session retains a historical tech option while another
    // session owns the technician's only active attempt.
    $selectedAttempt->update(['status'=>AiSchedulingAttempt::STATUS_SUPERSEDED]);
    $other = bindingLiveSession($this, bindingJob($this, 2), AiSchedulingSession::MODE_LIVE, AiSchedulingSession::MODE_OFF);
    $otherAttempt = bindingAttempt($other, $this->tech);
    $other->update(['current_attempt_id'=>$otherAttempt->id]);
    $selectedBefore = $selected->turns()->count();
    $otherBefore = $other->turns()->count();

    $this->service->simulateTechReply($selected, $this->tech, 'si');

    expect($selected->turns()->count())->toBeGreaterThan($selectedBefore)
        ->and($other->turns()->count())->toBe($otherBefore)
        ->and($selected->turns()->whereNotNull('inbound_message_id')->exists())->toBeTrue()
        ->and($other->turns()->whereNotNull('inbound_message_id')->exists())->toBeFalse();
});

it('does not create or route a simulated tech reply for a tech outside the selected session', function () {
    $selected = bindingLiveSession($this, bindingJob($this, 3), AiSchedulingSession::MODE_SIMULATE, AiSchedulingSession::MODE_OFF);
    $attempt = bindingAttempt($selected, $this->tech);
    $selected->update(['current_attempt_id'=>$attempt->id]);
    $other = bindingLiveSession($this, bindingJob($this, 4), AiSchedulingSession::MODE_LIVE, AiSchedulingSession::MODE_OFF);
    $otherAttempt = bindingAttempt($other, $this->otherTech);
    $other->update(['current_attempt_id'=>$otherAttempt->id]);
    $turnsBefore = AiSchedulingTurn::count();
    $inboundBefore = DB::table('inbound_messages')->count();

    $this->service->simulateTechReply($selected, $this->otherTech, 'si');

    expect(DB::table('inbound_messages')->count())->toBe($inboundBefore)
        ->and(AiSchedulingTurn::count())->toBe($turnsBefore);
});

it('routes a simulated customer reply to the selected session when that customer has another live session', function () {
    $customer = Customer::create(['phone_e164'=>'+12025550999', 'first_name'=>'Shared']);
    $selected = bindingLiveSession($this, bindingJob($this, 5, $customer), AiSchedulingSession::MODE_LIVE, AiSchedulingSession::MODE_SIMULATE);
    $other = bindingLiveSession($this, bindingJob($this, 6, $customer), AiSchedulingSession::MODE_LIVE, AiSchedulingSession::MODE_SIMULATE);
    $selectedBefore = $selected->turns()->count();
    $otherBefore = $other->turns()->count();

    $this->service->simulateCustomerReply($selected, $customer, 'yes');

    expect($selected->turns()->count())->toBeGreaterThan($selectedBefore)
        ->and($other->turns()->count())->toBe($otherBefore)
        ->and($selected->turns()->whereNotNull('inbound_message_id')->exists())->toBeTrue()
        ->and($other->turns()->whereNotNull('inbound_message_id')->exists())->toBeFalse();
});

it('does not create or route a simulated customer reply for a customer outside the selected session', function () {
    $selectedCustomer = Customer::create(['phone_e164'=>'+12025550888', 'first_name'=>'Selected']);
    $otherCustomer = Customer::create(['phone_e164'=>'+12025550777', 'first_name'=>'Other']);
    $selected = bindingLiveSession($this, bindingJob($this, 7, $selectedCustomer), AiSchedulingSession::MODE_LIVE, AiSchedulingSession::MODE_SIMULATE);
    $other = bindingLiveSession($this, bindingJob($this, 8, $otherCustomer), AiSchedulingSession::MODE_LIVE, AiSchedulingSession::MODE_SIMULATE);
    $turnsBefore = AiSchedulingTurn::count();
    $inboundBefore = DB::table('inbound_messages')->count();

    $this->service->simulateCustomerReply($selected, $otherCustomer, 'yes');

    expect(DB::table('inbound_messages')->count())->toBe($inboundBefore)
        ->and(AiSchedulingTurn::count())->toBe($turnsBefore)
        ->and($other->turns()->whereNotNull('inbound_message_id')->exists())->toBeFalse();
});

it('keeps real inbound replies on the existing participant-based routing', function () {
    $selected = bindingLiveSession($this, bindingJob($this, 9), AiSchedulingSession::MODE_SIMULATE, AiSchedulingSession::MODE_OFF);
    $oldAttempt = bindingAttempt($selected, $this->tech);
    $oldAttempt->update(['status'=>AiSchedulingAttempt::STATUS_SUPERSEDED]);
    $other = bindingLiveSession($this, bindingJob($this, 10), AiSchedulingSession::MODE_LIVE, AiSchedulingSession::MODE_OFF);
    $currentAttempt = bindingAttempt($other, $this->tech);
    $other->update(['current_attempt_id'=>$currentAttempt->id]);
    $selectedBefore = $selected->turns()->count();
    $otherBefore = $other->turns()->count();
    $inbound = InboundMessage::create([
        'employee_id'=>$this->tech->id, 'from_phone_e164'=>(string) $this->tech->phone,
        'to_phone_e164'=>'', 'body'=>'si', 'received_at'=>now(), 'processed'=>true,
    ]);

    $this->service->handleTechReply($this->tech, $inbound);

    expect($selected->turns()->count())->toBe($selectedBefore)
        ->and($other->turns()->count())->toBeGreaterThan($otherBefore)
        ->and($selected->turns()->whereNotNull('inbound_message_id')->exists())->toBeFalse()
        ->and($other->turns()->whereNotNull('inbound_message_id')->exists())->toBeTrue();
});

it('keeps real customer replies on the existing latest-session routing', function () {
    $customer = Customer::create(['phone_e164'=>'+12025550666', 'first_name'=>'Inbound']);
    $older = bindingLiveSession($this, bindingJob($this, 11, $customer), AiSchedulingSession::MODE_LIVE, AiSchedulingSession::MODE_SIMULATE);
    $latest = bindingLiveSession($this, bindingJob($this, 12, $customer), AiSchedulingSession::MODE_LIVE, AiSchedulingSession::MODE_SIMULATE);
    $olderBefore = $older->turns()->count();
    $latestBefore = $latest->turns()->count();
    $inbound = InboundMessage::create([
        'customer_id'=>$customer->id, 'from_phone_e164'=>(string) $customer->phone_e164,
        'to_phone_e164'=>'', 'body'=>'yes', 'received_at'=>now(), 'processed'=>true,
    ]);

    $this->service->handleCustomerReply($customer, $inbound);

    expect($older->turns()->count())->toBe($olderBefore)
        ->and($latest->turns()->count())->toBeGreaterThan($latestBefore)
        ->and($older->turns()->whereNotNull('inbound_message_id')->exists())->toBeFalse()
        ->and($latest->turns()->whereNotNull('inbound_message_id')->exists())->toBeTrue();
});
