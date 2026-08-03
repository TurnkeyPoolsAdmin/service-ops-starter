<?php

use App\Enums\JobStatus;
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
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    Carbon::setTestNow(Carbon::parse('2026-07-13 08:30', 'America/Los_Angeles')); // Monday

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

afterEach(fn () => Carbon::setTestNow());

function holdTestJob($test, string $firstName = 'Alan', string $phone = '+18185551234'): Job
{
    return $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => $phone,
        'first_name' => $firstName,
        'quote_amount_cents' => 45000,
    ], $test->actor);
}

// ---- The hold itself ----

it('takes a held job off the scheduling queue without changing its status', function () {
    $job = holdTestJob($this);

    expect(Job::needsScheduling()->pluck('id'))->toContain($job->id);

    $this->lifecycle->placeOnSchedulingHold($job, 'Customer is checking their schedule', $this->actor);

    $job->refresh();

    // Still an unscheduled job — a hold is a stamp, never a status.
    expect($job->status)->toBe(JobStatus::PendingSchedule)
        ->and($job->isOnSchedulingHold())->toBeTrue()
        ->and($job->scheduling_hold_reason)->toBe('Customer is checking their schedule')
        ->and($job->scheduling_hold_by)->toBe($this->actor->id)
        ->and(Job::needsScheduling()->pluck('id'))->not->toContain($job->id)
        ->and(Job::onSchedulingHold()->pluck('id'))->toContain($job->id);
});

it('drops the scheduling task off a held job so Open Tasks stays actionable', function () {
    $job = holdTestJob($this);

    expect($job->openTaskTypes())->toContain('scheduling');

    $this->lifecycle->placeOnSchedulingHold($job, 'Waiting on the quote approval', $this->actor);

    expect($job->refresh()->openTaskTypes())->not->toContain('scheduling');
});

it('logs the hold and the release with the reason', function () {
    $job = holdTestJob($this);

    $this->lifecycle->placeOnSchedulingHold($job, 'Customer is checking with insurance', $this->actor);
    $this->lifecycle->releaseSchedulingHold($job->refresh(), $this->actor, 'They called back');

    $events = Activity::where('subject_id', $job->id)->pluck('event');

    expect($events)->toContain('scheduling_hold')
        ->and($events)->toContain('scheduling_hold_released');

    $hold = Activity::where('subject_id', $job->id)->where('event', 'scheduling_hold')->first();
    expect($hold->properties['reason'])->toBe('Customer is checking with insurance');
});

it('puts a released job straight back on the scheduling queue', function () {
    $job = holdTestJob($this);

    $this->lifecycle->placeOnSchedulingHold($job, 'Customer asked us to call back Friday', $this->actor);
    $this->lifecycle->releaseSchedulingHold($job->refresh(), $this->actor);

    $job->refresh();

    expect($job->isOnSchedulingHold())->toBeFalse()
        ->and($job->scheduling_hold_reason)->toBeNull()
        ->and($job->scheduling_hold_by)->toBeNull()
        ->and(Job::needsScheduling()->pluck('id'))->toContain($job->id)
        ->and(Job::onSchedulingHold()->pluck('id'))->not->toContain($job->id);
});

it('refuses to hold a job that is already scheduled', function () {
    $job = holdTestJob($this);

    $this->lifecycle->schedule(
        $job,
        Carbon::parse('2026-07-15 09:00', 'America/Los_Angeles'),
        $this->actor,
        technicianId: $this->tech->id,
    );

    $this->lifecycle->placeOnSchedulingHold($job->refresh(), 'Nothing to wait for', $this->actor);

    expect($job->refresh()->scheduling_hold_at)->toBeNull();
});

it('clears the hold when the job is finally scheduled', function () {
    $job = holdTestJob($this);

    $this->lifecycle->placeOnSchedulingHold($job, 'Customer is checking their schedule', $this->actor);

    $this->lifecycle->schedule(
        $job->refresh(),
        Carbon::parse('2026-07-15 09:00', 'America/Los_Angeles'),
        $this->actor,
        technicianId: $this->tech->id,
    );

    $job->refresh();

    expect($job->status)->toBe(JobStatus::Scheduled)
        ->and($job->scheduling_hold_at)->toBeNull()
        ->and($job->scheduling_hold_reason)->toBeNull();
});

it('re-holding updates the reason and re-stamps the clock', function () {
    $job = holdTestJob($this);

    $this->lifecycle->placeOnSchedulingHold($job, 'Customer is checking their schedule', $this->actor);
    $firstStamp = $job->refresh()->scheduling_hold_at;

    Carbon::setTestNow(Carbon::parse('2026-07-16 08:30', 'America/Los_Angeles'));
    $this->lifecycle->placeOnSchedulingHold($job, 'Chased again - still deciding', $this->actor);

    $job->refresh();

    expect($job->scheduling_hold_reason)->toBe('Chased again - still deciding')
        ->and($job->scheduling_hold_at->gt($firstStamp))->toBeTrue()
        ->and($job->schedulingHoldDays())->toBe(0);
});

// ---- The job screen ----

it('holds and releases a job from the job actions menu', function () {
    $job = holdTestJob($this);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction(TestAction::make('putOnSchedulingHold'), ['reason' => 'Customer is checking their schedule']);

    expect($job->refresh()->isOnSchedulingHold())->toBeTrue();

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction(TestAction::make('releaseSchedulingHold'), []);

    expect($job->refresh()->isOnSchedulingHold())->toBeFalse();
});

it('requires a reason to put a job on hold', function () {
    $job = holdTestJob($this);

    Livewire::test(ViewJob::class, ['record' => $job->getRouteKey()])
        ->callAction(TestAction::make('putOnSchedulingHold'), ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    expect($job->refresh()->isOnSchedulingHold())->toBeFalse();
});

// ---- The Telegram digest ----

it('moves a held job out of Needs scheduling and into its own digest section', function () {
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)]);

    $waiting = holdTestJob($this, 'Held', '+18185550001');
    holdTestJob($this, 'Active', '+18185550002');

    $this->lifecycle->placeOnSchedulingHold($waiting, 'Customer is checking their schedule', $this->actor);

    // Held two days ago, so the digest can say how long it has sat.
    $waiting->forceFill(['scheduling_hold_at' => Carbon::parse('2026-07-11 09:00', 'America/Los_Angeles')])->save();

    $this->artisan('alerts:telegram-digest --when=morning')->assertSuccessful();

    Http::assertSent(function ($req) {
        $text = $req['text'];

        // "Held" appears only under the hold heading, never as work to do now.
        return str_contains($text, 'Needs scheduling')
            && str_contains($text, 'Active')
            && str_contains($text, 'On hold - waiting on the customer') === false
            && str_contains($text, 'On hold')
            && str_contains($text, 'Held')
            && str_contains($text, 'Customer is checking their schedule')
            && str_contains($text, 'on hold 2 days');
    });
});

it('drops the on-hold section entirely when nothing is held', function () {
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)]);

    holdTestJob($this, 'Active', '+18185550002');

    $this->artisan('alerts:telegram-digest --when=morning')->assertSuccessful();

    Http::assertSent(fn ($req) => ! str_contains($req['text'], 'On hold'));
});

it('keeps reposting the hold in every digest slot', function () {
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)]);

    $job = holdTestJob($this, 'Held', '+18185550001');
    $this->lifecycle->placeOnSchedulingHold($job, 'Customer is checking their schedule', $this->actor);

    foreach (['morning', 'midday', 'evening'] as $slot) {
        $this->artisan('alerts:telegram-digest --when='.$slot)->assertSuccessful();
    }

    // A live list with no per-job stamp — every slot names it again.
    Http::assertSentCount(3);
});
