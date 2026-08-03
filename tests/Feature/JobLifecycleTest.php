<?php

use App\Enums\IssueType;
use App\Enums\JobStatus;
use App\Enums\OptOutSource;
use App\Enums\PaymentMethod;
use App\Enums\TemplateKey;
use App\Jobs\SendSmsJob;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Issue;
use App\Models\Job;
use App\Models\ScheduledMessage;
use App\Models\Warehouse;
use App\Services\JobLifecycle;
use App\Services\Messaging\OutboxService;
use App\Support\Timezone;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed([
        RoleSeeder::class,
        EntitySeeder::class,
        WarehouseSeeder::class,
        MessageTemplateSeeder::class,
        SettingSeeder::class,
    ]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->lifecycle = app(JobLifecycle::class);
});

afterEach(fn () => Carbon::setTestNow());

function intakeData(): array
{
    return [
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Alan',
        'last_name' => 'Fairview',
        'vehicle_year' => '2022', 'vehicle_make' => 'Tesla', 'vehicle_model' => 'Model Y',
        'vin' => '7SAYGDEF3NF000287', 'glass_position' => 'windshield',
        'quote_amount_cents' => 95000, 'part_cost_cents' => 35000,
        'requested_daytime_raw' => 'Thursday',
        'intake_raw_text' => 'raw', 'parsed_payload_json' => ['x' => 1],
        'parse_status' => 'clean',
    ];
}

it('revives a soft-deleted customer with the same phone instead of failing', function () {
    // A previously-deleted customer holds the unique phone_e164; intake for that
    // number must restore them, not collide on the unique index.
    $deleted = Customer::create(['phone_e164' => '+18185551234', 'first_name' => 'Old']);
    $deleted->delete();
    expect($deleted->fresh()?->trashed())->toBeTrue();

    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);

    expect($job->customer_id)->toBe($deleted->id)
        ->and(Customer::find($deleted->id)?->trashed())->toBeFalse()
        ->and(Customer::where('phone_e164', '+18185551234')->count())->toBe(1);
});

it('creates a customer + pending job WITHOUT auto-queuing a welcome on intake', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);

    expect($job->status)->toBe(JobStatus::PendingSchedule)
        ->and($job->job_number)->toMatch('/^LA-\d{4}-0001$/')
        ->and($job->created_by)->toBe($this->actor->id)
        ->and(Customer::where('phone_e164', '+18185551234')->exists())->toBeTrue();

    // The dispatcher now picks the welcome on the SendWelcome screen — nothing
    // is queued automatically.
    expect($job->scheduledMessages()->count())->toBe(0);

    expect(activityForJob($job)->pluck('event'))->toContain('lead_created');
});

it('sendWelcome (acknowledged) queues a single dispatch intro via openphone', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-24 12:00', 'America/Los_Angeles')); // Wed, business hours
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);

    $this->lifecycle->sendWelcome($job, salesAcknowledged: true, actor: $this->actor);

    $rows = $job->scheduledMessages()->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->template_key->value)->toBe('welcome_dispatch_intro')
        ->and($rows->first()->provider)->toBe('openphone')
        ->and($rows->first()->scheduled_for)->toBeNull()
        ->and($rows->first()->from_phone_e164)->toBe('+18185550000');

    expect(activityForJob($job)->pluck('event'))->toContain('welcome_sent');
});

it('sendWelcome (not acknowledged) sends a Podium handoff now + a Quo intro 45s later', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::parse('2026-06-24 12:00', 'America/Los_Angeles')); // Wed, business hours
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);

    $this->lifecycle->sendWelcome($job, salesAcknowledged: false, actor: $this->actor);

    $handoff = $job->scheduledMessages()->where('template_key', 'welcome_podium_handoff')->first();
    $intro = $job->scheduledMessages()->where('template_key', 'welcome_dispatch_intro')->first();

    expect($job->scheduledMessages()->count())->toBe(2)
        ->and($handoff->provider)->toBe('podium')
        ->and($handoff->scheduled_for)->toBeNull()
        ->and($intro->provider)->toBe('openphone')
        ->and($intro->scheduled_for)->not->toBeNull()
        // ~45s out (allow a little slack for execution time within the test).
        ->and($intro->scheduled_for->greaterThan(now()->addSeconds(40)))->toBeTrue()
        ->and($intro->scheduled_for->lessThanOrEqualTo(now()->addSeconds(46)))->toBeTrue();

    // Both rows are dispatched immediately (the Quo one carrying a 45s delay).
    Queue::assertPushed(SendSmsJob::class, 2);
});

it('sendWelcome is idempotent — a second call adds no rows', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);

    $this->lifecycle->sendWelcome($job, salesAcknowledged: true, actor: $this->actor);
    $this->lifecycle->sendWelcome($job, salesAcknowledged: false, actor: $this->actor);

    expect($job->scheduledMessages()->count())->toBe(1);
});

it('findOrCreate reuses an existing customer by phone', function () {
    $a = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $b = $this->lifecycle->createFromIntake(intakeData(), $this->actor);

    expect(Customer::count())->toBe(1)
        ->and($b->job_number)->toBe('LA-'.now()->setTimezone(Timezone::display())->format('Y').'-0002');
});

function technician($test): Employee
{
    return Employee::create([
        'name' => 'Tess Tech', 'email' => 'tess@test.local', 'can_login' => false,
        'active' => true, 'password' => bcrypt('x'), 'job_functions' => ['technician'],
    ]);
}

it('schedules with a tech, queues a time confirmation, and creates two reminders', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $apptUtc = Timezone::now()->addDays(5)->setTime(14, 0)->utc();

    $this->lifecycle->schedule(
        $job, $apptUtc, $this->actor,
        technicianId: technician($this)->id,
        warehouseId: Warehouse::first()->id,
    );
    $job->refresh();

    expect($job->status)->toBe(JobStatus::Scheduled)
        ->and($job->scheduled_by)->toBe($this->actor->id);

    expect($job->scheduledMessages()->where('template_key', 'time_confirmation')->count())->toBe(1);
    expect($job->scheduledMessages()->where('message_type', 'reminder')->count())->toBe(2);
});

it('defers the time confirmation until a technician is assigned', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $apptUtc = Timezone::now()->addDays(5)->setTime(14, 0)->utc();

    // Scheduled with no tech yet → no time confirmation.
    $this->lifecycle->schedule($job, $apptUtc, $this->actor, technicianId: null);
    expect($job->refresh()->scheduledMessages()->where('template_key', 'time_confirmation')->count())->toBe(0);

    // Assigning the tech now fires the confirmation, exactly once.
    $this->lifecycle->assignTechnician($job, technician($this)->id, $this->actor);
    expect($job->refresh()->scheduledMessages()->where('template_key', 'time_confirmation')->count())->toBe(1);

    // Re-assigning a different tech does not send it again.
    $again = Employee::create([
        'name' => 'Tom Tech', 'email' => 'tom@test.local', 'can_login' => false,
        'active' => true, 'password' => bcrypt('x'), 'job_functions' => ['technician'],
    ]);
    $this->lifecycle->assignTechnician($job, $again->id, $this->actor);
    expect($job->refresh()->scheduledMessages()->where('template_key', 'time_confirmation')->count())->toBe(1);
});

it('reschedule increments the counter and rebuilds reminders', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $first = Timezone::now()->addDays(5)->setTime(14, 0)->utc();
    $this->lifecycle->schedule($job, $first, $this->actor);

    $original = $job->refresh()->scheduledMessages()->where('message_type', 'reminder')->pluck('id');

    $second = Timezone::now()->addDays(8)->setTime(9, 0)->utc();
    $this->lifecycle->schedule($job, $second, $this->actor);
    $job->refresh();

    expect($job->rescheduled_count)->toBe(1);
    // Old reminders canceled; a fresh queued set exists.
    expect($job->scheduledMessages()->whereIn('id', $original)->where('status', 'canceled')->count())->toBe(2);
    expect($job->scheduledMessages()->where('message_type', 'reminder')->where('status', 'queued')->count())->toBe(2);
});

it('sends one reschedule notice per distinct reschedule', function () {
    $tech = technician($this);
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $this->lifecycle->schedule($job, Timezone::now()->addDays(5)->setTime(14, 0)->utc(), $this->actor,
        technicianId: $tech->id);

    // First reschedule → notify.
    $this->lifecycle->schedule($job->refresh(), Timezone::now()->addDays(8)->setTime(9, 0)->utc(), $this->actor,
        technicianId: $tech->id);
    $this->lifecycle->sendRescheduleNotice($job->refresh(), $this->actor);
    expect($job->scheduledMessages()->where('template_key', 'appointment_rescheduled')->count())->toBe(1);

    // Re-notifying for the SAME reschedule is idempotent (keyed on the count).
    $this->lifecycle->sendRescheduleNotice($job->refresh(), $this->actor);
    expect($job->scheduledMessages()->where('template_key', 'appointment_rescheduled')->count())->toBe(1);

    // A second, distinct reschedule sends a fresh notice.
    $this->lifecycle->schedule($job->refresh(), Timezone::now()->addDays(10)->setTime(11, 0)->utc(), $this->actor,
        technicianId: $tech->id);
    $this->lifecycle->sendRescheduleNotice($job->refresh(), $this->actor);
    expect($job->scheduledMessages()->where('template_key', 'appointment_rescheduled')->count())->toBe(2);
});

it('cancelAppointment pulls a scheduled job back to the queue, off the calendar, texting both parties', function () {
    $tech = Employee::create([
        'name' => 'Phone Tech', 'email' => 'phonetech@test.local', 'can_login' => false,
        'active' => true, 'password' => bcrypt('x'), 'job_functions' => ['technician'],
        'phone' => '+18185559999', 'preferred_language' => 'es',
    ]);
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $this->lifecycle->schedule($job, Timezone::now()->addDays(5)->setTime(14, 0)->utc(), $this->actor,
        technicianId: $tech->id);
    $job->refresh();
    expect($job->scheduledMessages()->where('message_type', 'reminder')->where('status', 'queued')->count())->toBe(2);

    $result = $this->lifecycle->cancelAppointment($job, 'customer needs to reschedule', $this->actor);
    expect($result->getAttribute('wasPartsOrdered'))->toBeFalse();
    $job->refresh();

    // Back to the scheduling queue, off the calendar, tech kept.
    expect($job->status)->toBe(JobStatus::PendingSchedule)
        ->and($job->scheduled_at)->toBeNull()
        ->and($job->technician_id)->toBe($tech->id);

    // Unsent reminders/confirmations canceled.
    expect($job->scheduledMessages()->where('message_type', 'reminder')->where('status', 'queued')->count())->toBe(0);

    // Both notices queued. One tech_job_canceled template, rendered from its
    // Spanish row because that is the tech's preference.
    expect($job->scheduledMessages()->where('template_key', 'appointment_canceled_pending_reschedule')->count())->toBe(1);
    $techNotice = $job->scheduledMessages()->where('template_key', 'tech_job_canceled')->sole();
    expect($techNotice->rendered_body)->toContain('Se canceló un trabajo')
        ->and($techNotice->rendered_body)->not->toContain('A job was canceled');

    expect(activityForJob($job)->pluck('event'))->toContain('appointment_canceled');
});

it('cancelAppointment renders the tech notice in the tech preferred language (English)', function () {
    $tech = Employee::create([
        'name' => 'Eng Tech', 'email' => 'engtech@test.local', 'can_login' => false,
        'active' => true, 'password' => bcrypt('x'), 'job_functions' => ['technician'],
        'phone' => '+18185558888', 'preferred_language' => 'en',
    ]);
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $this->lifecycle->schedule($job, Timezone::now()->addDays(5)->setTime(14, 0)->utc(), $this->actor,
        technicianId: $tech->id);

    $this->lifecycle->cancelAppointment($job->refresh(), null, $this->actor);

    $techNotice = $job->refresh()->scheduledMessages()->where('template_key', 'tech_job_canceled')->sole();
    expect($techNotice->rendered_body)->toContain('A job was canceled off your schedule')
        ->and($techNotice->rendered_body)->not->toContain('Se canceló');
});

it('cancelAppointment un-stamps an ordered part so it re-surfaces on reschedule', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $this->lifecycle->schedule($job, Timezone::now()->addDays(5)->setTime(14, 0)->utc(), $this->actor,
        technicianId: technician($this)->id);
    $this->lifecycle->markPartsOrdered($job->refresh(), $this->actor);
    expect($job->refresh()->isPartsOrdered())->toBeTrue();

    $result = $this->lifecycle->cancelAppointment($job->refresh(), null, $this->actor);

    expect($result->getAttribute('wasPartsOrdered'))->toBeTrue()
        ->and($job->refresh()->isPartsOrdered())->toBeFalse();
});

it('cancelAppointment with a picked-up part flags a return and texts the tech', function () {
    $tech = Employee::create([
        'name' => 'Return Tech', 'email' => 'returntech@test.local', 'can_login' => false,
        'active' => true, 'password' => bcrypt('x'), 'job_functions' => ['technician'],
        'phone' => '+18185557777', 'preferred_language' => 'es',
    ]);
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $this->lifecycle->schedule($job, Timezone::now()->addDays(5)->setTime(14, 0)->utc(), $this->actor,
        technicianId: $tech->id);

    $this->lifecycle->cancelAppointment($job->refresh(), null, $this->actor, partPickedUp: true);
    $job->refresh();

    // Flagged for return + on the "Parts returned" queue.
    expect($job->needsPartReturn())->toBeTrue()
        ->and($job->part_return_needed_at)->not->toBeNull()
        ->and(Job::partsReturnPending()->pluck('id'))->toContain($job->id);

    // The tech got a return-the-part text, rendered from the Spanish row.
    $returnNotice = $job->scheduledMessages()->where('template_key', 'tech_return_part')->sole();
    expect($returnNotice->rendered_body)->toContain('devuelve la pieza')
        ->and($returnNotice->rendered_body)->not->toContain('return the part');
});

it('cancelAppointment without a picked-up part flags no return and sends no return text', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $this->lifecycle->schedule($job, Timezone::now()->addDays(5)->setTime(14, 0)->utc(), $this->actor,
        technicianId: technician($this)->id);

    $this->lifecycle->cancelAppointment($job->refresh(), null, $this->actor, partPickedUp: false);
    $job->refresh();

    expect($job->needsPartReturn())->toBeFalse()
        ->and($job->scheduledMessages()->where('template_key', 'tech_return_part')->count())->toBe(0);
});

it('markPartReturned clears the return flag; a re-cancel does not reset an existing return', function () {
    $tech = technician($this);
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $this->lifecycle->schedule($job, Timezone::now()->addDays(5)->setTime(14, 0)->utc(), $this->actor,
        technicianId: $tech->id);
    $this->lifecycle->cancelAppointment($job->refresh(), null, $this->actor, partPickedUp: true);

    $this->lifecycle->markPartReturned($job->refresh(), $this->actor);
    $job->refresh();
    expect($job->needsPartReturn())->toBeFalse()
        ->and($job->isPartReturned())->toBeTrue()
        ->and(Job::partsReturnPending()->pluck('id'))->not->toContain($job->id);

    // markPartReturned is a no-op once already returned / when never flagged.
    $returnedAt = $job->part_returned_at;
    $this->lifecycle->markPartReturned($job->refresh(), $this->actor);
    expect($job->refresh()->part_returned_at->equalTo($returnedAt))->toBeTrue();

    // Re-scheduling then re-cancelling with a picked-up part does not overwrite
    // the completed return.
    $this->lifecycle->schedule($job->refresh(), Timezone::now()->addDays(7)->setTime(9, 0)->utc(), $this->actor,
        technicianId: $tech->id);
    $this->lifecycle->cancelAppointment($job->refresh(), null, $this->actor, partPickedUp: true);
    expect($job->refresh()->isPartReturned())->toBeTrue();
});

it('techCancelled removes only the tech, keeps the appointment, and texts nobody', function () {
    $tech = Employee::create([
        'name' => 'Bailed Tech', 'email' => 'bailed@test.local', 'can_login' => false,
        'active' => true, 'password' => bcrypt('x'), 'job_functions' => ['technician'],
        'phone' => '+18185551212', 'preferred_language' => 'es', 'fixed_cost_cents' => 9000,
    ]);
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $this->lifecycle->schedule($job, Timezone::now()->addDays(5)->setTime(14, 0)->utc(), $this->actor,
        technicianId: $tech->id);
    $job->refresh();
    $scheduledAt = $job->scheduled_at;
    expect($job->tech_pay_amount_cents)->toBe(9000)
        ->and($job->scheduledMessages()->where('message_type', 'reminder')->where('status', 'queued')->count())->toBe(2);

    $countBefore = $job->scheduledMessages()->count();
    $result = $this->lifecycle->techCancelled($job, 'called out sick', $this->actor);
    $job->refresh();

    // Tech gone + pay reset, but the appointment (status, time) and reminders stand.
    expect($job->technician_id)->toBeNull()
        ->and($job->tech_pay_amount_cents)->toBeNull()
        ->and($job->status)->toBe(JobStatus::Scheduled)
        ->and($job->scheduled_at->equalTo($scheduledAt))->toBeTrue()
        ->and($job->scheduledMessages()->where('message_type', 'reminder')->where('status', 'queued')->count())->toBe(2);

    // Nothing new was queued to anyone (customer or tech).
    expect($job->scheduledMessages()->count())->toBe($countBefore);

    // Surfaces on the Needs-tech-assigned queue, and the move is logged.
    expect(Job::needsTechAssigned()->pluck('id'))->toContain($job->id)
        ->and(activityForJob($result)->pluck('event'))->toContain('tech_canceled');
});

it('cancels unsent reminders when work is completed', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $this->lifecycle->schedule($job, Timezone::now()->addDays(5)->setTime(14, 0)->utc(), $this->actor,
        technicianId: technician($this)->id);

    $this->lifecycle->markWorkComplete($job->refresh(), $this->actor);
    $job->refresh();

    expect($job->status)->toBe(JobStatus::WorkCompleted)
        ->and($job->work_completed_at)->not->toBeNull()
        ->and($job->scheduledMessages()->where('message_type', 'reminder')->where('status', 'queued')->count())->toBe(0);
});

it('records payment and sets the recorder (no review sequence)', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $job->update(['technician_id' => technician($this)->id]);

    $this->lifecycle->recordPayment($job, 95000, PaymentMethod::Zelle, 'zelle-123', 'paid in full', $this->actor);
    $job->refresh();

    expect($job->payment_received_at)->not->toBeNull()
        ->and($job->payment_recorded_by)->toBe($this->actor->id)
        ->and($job->amount_collected_cents)->toBe(95000)
        ->and($job->scheduledMessages()->where('message_type', 'review')->count())->toBe(0);

    expect(activityForJob($job)->pluck('event'))->toContain('payment_recorded');
});

it('sends a job-complete text when work is marked complete', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $job->update(['technician_id' => technician($this)->id]);

    $this->lifecycle->markWorkComplete($job, $this->actor);

    expect($job->refresh()->scheduledMessages()->where('template_key', 'job_complete')->count())->toBe(1);
});

it('completes the job when work done + paid + tech paid', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $job->update(['technician_id' => technician($this)->id]);
    $this->lifecycle->markWorkComplete($job, $this->actor);
    $this->lifecycle->recordPayment($job->refresh(), 95000, PaymentMethod::Cash, null, null, $this->actor);
    $this->lifecycle->markTechPaid($job->refresh(), 20000, PaymentMethod::Venmo, 'v-1', $this->actor);
    $job->refresh();

    expect($job->status)->toBe(JobStatus::Completed)
        ->and($job->completed_at)->not->toBeNull()
        ->and($job->gross_margin_cents)->toBe(95000 - 35000 - 20000);
});

it('cancel cancels all unsent messages for the job', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $this->lifecycle->schedule($job, Timezone::now()->addDays(5)->setTime(14, 0)->utc(), $this->actor);

    $this->lifecycle->cancel($job->refresh(), 'customer flaked', $this->actor);
    $job->refresh();

    expect($job->status)->toBe(JobStatus::Canceled)
        ->and($job->scheduledMessages()->unsent()->count())->toBe(0);
});

it('reopen uncancels a closed job back to needs scheduling, wiping the old appointment', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $this->lifecycle->schedule($job, Timezone::now()->addDays(5)->setTime(14, 0)->utc(), $this->actor,
        technicianId: technician($this)->id);
    $this->lifecycle->markPartsOrdered($job->refresh(), $this->actor);
    $this->lifecycle->cancel($job->refresh(), 'customer flaked', $this->actor);

    expect($job->refresh()->status)->toBe(JobStatus::Canceled);

    $result = $this->lifecycle->reopen($job->refresh(), $this->actor);
    $job->refresh();

    // Fresh lead again: back on the queue, no appointment/tech/part, stamps cleared.
    expect($job->status)->toBe(JobStatus::PendingSchedule)
        ->and($job->scheduled_at)->toBeNull()
        ->and($job->scheduled_by)->toBeNull()
        ->and($job->technician_id)->toBeNull()
        ->and($job->tech_pay_amount_cents)->toBeNull()
        ->and($job->isPartsOrdered())->toBeFalse()
        ->and($job->rescheduled_count)->toBe(0)
        ->and($job->canceled_at)->toBeNull()
        ->and($job->cancellation_reason)->toBeNull();

    expect(activityForJob($job)->pluck('event'))->toContain('reopened');
});

it('reopen also uncancels a lost job and clears its lost_reason', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $this->lifecycle->schedule($job, Timezone::now()->addDays(5)->setTime(14, 0)->utc(), $this->actor);
    $this->lifecycle->markLost($job->refresh(), 'no answer', $this->actor);
    expect($job->refresh()->status)->toBe(JobStatus::Lost);

    $this->lifecycle->reopen($job->refresh(), $this->actor);

    expect($job->refresh()->status)->toBe(JobStatus::PendingSchedule)
        ->and($job->lost_reason)->toBeNull();
});

it('reopen is a no-op on a job that is not closed', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $this->lifecycle->schedule($job, Timezone::now()->addDays(5)->setTime(14, 0)->utc(), $this->actor);

    $this->lifecycle->reopen($job->refresh(), $this->actor);

    // Still scheduled — nothing wiped.
    expect($job->refresh()->status)->toBe(JobStatus::Scheduled)
        ->and($job->scheduled_at)->not->toBeNull();
});

it('deleteCompletely soft-removes the job, clears its message rows, and queues a job_deleted issue', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $jobId = $job->id;

    // Give the job a message row to prove deleteCompletely clears it.
    $this->lifecycle->sendWelcome($job, salesAcknowledged: true, actor: $this->actor);
    expect($job->scheduledMessages()->count())->toBeGreaterThan(0);

    $this->lifecycle->deleteCompletely($job, $this->actor);

    // Gone from every normal query (SoftDeletes global scope) and its message
    // rows are cleared, but the row itself survives — trashed — until a super
    // admin clears the Issue it raised.
    expect(Job::find($jobId))->toBeNull()
        ->and(Job::withTrashed()->find($jobId))->not->toBeNull()
        ->and(Job::withTrashed()->find($jobId)->trashed())->toBeTrue()
        ->and(ScheduledMessage::where('job_id', $jobId)->count())->toBe(0);

    $issue = Issue::open()->where('type', IssueType::JobDeleted->value)->first();
    expect($issue)->not->toBeNull()
        ->and($issue->subject_id)->toBe($jobId);
});

it('opt-out blocks future sends and cancels unsent rows across jobs', function () {
    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $job->update(['technician_id' => technician($this)->id]);
    $this->lifecycle->recordPayment($job, 95000, PaymentMethod::Cash, null, null, $this->actor);
    $customer = $job->customer;

    $this->lifecycle->optOutCustomer($customer, OptOutSource::Manual, 'phoned in');
    $job->refresh();

    expect($customer->refresh()->do_not_text)->toBeTrue()
        ->and($customer->scheduledMessages()->unsent()->count())->toBe(0);

    // A subsequent queue attempt is gated out.
    $blocked = app(OutboxService::class)
        ->queueForJob($job, TemplateKey::JobComplete);
    expect($blocked)->toBeNull();
});

function activityForJob(Job $job)
{
    return Activity::query()
        ->where('subject_type', $job->getMorphClass())
        ->where('subject_id', $job->id)
        ->get();
}

it('generates the next job number from the highest suffix despite gaps', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 26, 12));

    // First job gets 0001; bump it to 0002 so there's a gap (no 0001 left). A
    // count-based sequence would now regenerate the colliding 0002.
    $first = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $first->update(['job_number' => 'LA-2026-0002']);

    $next = $this->lifecycle->createFromIntake(intakeData(), $this->actor);

    expect($next->job_number)->toBe('LA-2026-0003');
});

it('renumbers a job to the new entity prefix when its entity changes', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 26, 12));

    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    expect($job->job_number)->toBe('LA-2026-0001');

    // Re-file the LA lead to Del Mar — the "LA-…" number must not linger.
    $delmar = Entity::where('code', 'DelMar')->firstOrFail();
    $job->update(['entity_id' => $delmar->id]);

    expect($job->fresh()->job_number)->toBe('DelMar-2026-0001');
});

it('leaves the job number alone on a non-entity edit', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 26, 12));

    $job = $this->lifecycle->createFromIntake(intakeData(), $this->actor);
    $original = $job->job_number;

    $job->update(['vehicle_make' => 'Toyota']);

    expect($job->fresh()->job_number)->toBe($original);
});
