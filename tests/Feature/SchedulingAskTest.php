<?php

use App\Enums\Language;
use App\Enums\TemplateKey;
use App\Enums\TimePreference;
use App\Filament\Resources\Jobs\Support\JobActions;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Holiday;
use App\Models\Job;
use App\Models\ScheduledMessage;
use App\Services\JobLifecycle;
use App\Support\SchedulingAsk;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');
    $this->actingAs($this->actor);
    $this->lifecycle = app(JobLifecycle::class);

    // Monday, July 20 2026 at 10am Pacific. The next business day is Tuesday
    // the 21st, so "future" means the 22nd onward.
    Carbon::setTestNow(Carbon::parse('2026-07-20 17:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

function askJob($test, ?string $day, TimePreference $preference = TimePreference::None, ?string $time = null): Job
{
    return $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Alan',
        'requested_day' => $day,
        'requested_time' => $time,
        'requested_time_preference' => $preference->value,
    ], $test->actor)->refresh();
}

it('asks about the next business day when no date was requested', function () {
    $ask = SchedulingAsk::for(askJob($this, null));

    expect($ask?->template)->toBe(TemplateKey::SchedulingAskNoDate)
        ->and($ask->vars['earliest_day'])->toBe('tomorrow (Tuesday)');
});

it('asks for a time on a next-business-day request', function () {
    $ask = SchedulingAsk::for(askJob($this, '2026-07-21'));

    expect($ask?->template)->toBe(TemplateKey::SchedulingAskNextDay)
        ->and($ask->vars['requested_day'])->toBe('Tuesday, July 21');
});

it('confirms instead of asking once a preference is known', function () {
    $ask = SchedulingAsk::for(askJob($this, '2026-07-21', TimePreference::Morning));

    expect($ask?->template)->toBe(TemplateKey::SchedulingConfirmNextDay)
        ->and($ask->vars['requested_time'])->toBe('in the morning');
});

it('treats ASAP as an answer, not a blank', function () {
    $ask = SchedulingAsk::for(askJob($this, '2026-07-21', TimePreference::Asap));

    expect($ask?->template)->toBe(TemplateKey::SchedulingConfirmNextDay)
        ->and($ask->vars['requested_time'])->toBe('as early as we can');
});

it('echoes a specific clock time', function () {
    $ask = SchedulingAsk::for(askJob($this, '2026-07-21', TimePreference::Specific, '2pm'));

    expect($ask->vars['requested_time'])->toBe('at 2pm');
});

it('offers a single earlier day when only one sits before the request', function () {
    // Requested Wednesday; only Tuesday (the NBD) is earlier.
    $ask = SchedulingAsk::for(askJob($this, '2026-07-22'));

    expect($ask?->template)->toBe(TemplateKey::SchedulingAskFuture)
        ->and($ask->vars['earlier_window'])->toBe('tomorrow (Tuesday)');
});

it('widens to two named days when the request is further out', function () {
    // Requested Friday; Tuesday AND Wednesday are both earlier options.
    $ask = SchedulingAsk::for(askJob($this, '2026-07-24', TimePreference::Afternoon));

    expect($ask?->template)->toBe(TemplateKey::SchedulingConfirmFuture)
        ->and($ask->vars['earlier_window'])->toBe('tomorrow (Tuesday) or Wednesday');
});

it('has nothing to send for a job requested today or already past', function () {
    expect(SchedulingAsk::for(askJob($this, '2026-07-20')))->toBeNull()
        ->and(SchedulingAsk::for(askJob($this, '2026-07-15')))->toBeNull();
});

it('skips a Sunday when naming the next business day', function () {
    // Saturday, July 25 -> the next business day is Monday the 27th.
    Carbon::setTestNow(Carbon::parse('2026-07-25 17:00:00', 'UTC'));

    $ask = SchedulingAsk::for(askJob($this, null));

    expect($ask->vars['earliest_day'])->toBe('Monday');
});

it('skips a tagged holiday when naming the next business day', function () {
    Holiday::create(['name' => 'Test holiday', 'type' => 'annual', 'month' => 7, 'day' => 21]);

    $ask = SchedulingAsk::for(askJob($this, null));

    // Tuesday the 21st is out, so Wednesday the 22nd is the earliest.
    expect($ask->vars['earliest_day'])->toBe('Wednesday');
});

it('renders the ask in the customer language', function () {
    $job = askJob($this, '2026-07-24', TimePreference::Morning);
    $job->customer->update(['preferred_language' => Language::Spanish->value]);

    $ask = SchedulingAsk::for($job->refresh());

    expect($ask->vars['requested_time'])->toBe('por la mañana')
        ->and($ask->vars['requested_day'])->toBe('viernes, 24 de julio')
        ->and($ask->vars['earlier_window'])->toBe('mañana (martes) o el miércoles');
});

it('queues the resolved text with its day phrasings merged in', function () {
    $job = askJob($this, '2026-07-24');
    $ask = SchedulingAsk::for($job);

    $message = $this->lifecycle->sendManualMessage($job, $ask->template, $this->actor, $ask->vars);

    expect($message)->not->toBeNull()
        ->and($message->rendered_body)->toContain('Friday, July 24')
        ->and($message->rendered_body)->toContain('tomorrow (Tuesday)')
        // The merge var must actually resolve — an unfilled {token} blanks out.
        ->and($message->rendered_body)->not->toContain('{');
});

it('refuses to queue when the pull-earlier days are missing', function () {
    $job = askJob($this, '2026-07-24');

    // No extra vars: {earlier_window} is a required var, so nothing queues
    // rather than texting a sentence with a hole in it.
    $message = $this->lifecycle->sendManualMessage($job, TemplateKey::SchedulingAskFuture, $this->actor);

    expect($message)->toBeNull()
        ->and(ScheduledMessage::count())->toBe(0);
});

it('hides the action once the job is scheduled or the customer opted out', function () {
    $job = askJob($this, '2026-07-24');
    expect(JobActions::schedulingAskFor($job))->not->toBeNull();

    $job->customer->update(['do_not_text' => true]);
    expect(JobActions::schedulingAskFor($job->refresh()))->toBeNull();

    $job->customer->update(['do_not_text' => false]);
    $tech = Employee::create([
        'name' => 'Tech', 'email' => 'tech@test.local', 'active' => true,
        'job_functions' => ['technician'], 'phone' => '+18185550000',
    ]);
    $this->lifecycle->assignTechnician($job, $tech->id, $this->actor);
    $this->lifecycle->schedule($job, Carbon::parse('2026-07-24 16:00:00', 'UTC'), $this->actor);

    expect(JobActions::schedulingAskFor($job->refresh()))->toBeNull();
});

it('reads a legacy free-text time as a preference', function () {
    $job = askJob($this, '2026-07-24');
    // Jobs taken before the column existed only have the free text.
    $job->forceFill(['requested_time' => 'As soon as possible', 'requested_time_preference' => null])->save();

    expect($job->refresh()->timePreference())->toBe(TimePreference::Asap)
        ->and(SchedulingAsk::for($job)->template)->toBe(TemplateKey::SchedulingConfirmFuture);
});

it('classifies a customer with no time text as having no preference', function () {
    expect(TimePreference::normalize(null))->toBeNull()
        ->and(TimePreference::normalize('2:30pm'))->toBe(TimePreference::Specific)
        ->and(TimePreference::normalize('afternoon'))->toBe(TimePreference::Afternoon);
});

// ---- Ordering vs. the delayed welcome ----------------------------------
//
// The welcome is a delayed PAIR (Podium handoff now, Quo dispatch intro ~45s
// later) but "Send scheduling text" appears as soon as those rows exist. Sent
// on click it would land BETWEEN them, so the customer reads our scheduling
// question before the intro explaining who is texting.

it('holds the scheduling text behind a welcome intro that has not sent yet', function () {
    $job = askJob($this, '2026-07-24');
    $this->lifecycle->sendWelcome($job, salesAcknowledged: false, actor: $this->actor);

    $intro = $job->scheduledMessages()->whereNotNull('scheduled_for')->first();
    expect($intro->scheduled_for->timestamp)->toBe(now()->addSeconds(45)->timestamp);

    $ask = SchedulingAsk::for($job->refresh());
    $message = $this->lifecycle->sendManualMessage(
        $job, $ask->template, $this->actor, $ask->vars,
        $this->lifecycle->followUpSendAtUtc($job),
    );

    // 45s intro + the 30s follow-up gap — strictly after the intro, not between
    // the handoff and the intro.
    expect($message->scheduled_for)->not->toBeNull()
        ->and($message->scheduled_for->timestamp)->toBe(now()->addSeconds(75)->timestamp)
        ->and($message->scheduled_for->greaterThan($intro->scheduled_for))->toBeTrue();
});

it('sends the scheduling text immediately once the welcome has left the outbox', function () {
    $job = askJob($this, '2026-07-24');
    $this->lifecycle->sendWelcome($job, salesAcknowledged: false, actor: $this->actor);

    // Both welcome rows delivered — nothing left to wait behind.
    $job->scheduledMessages()->update(['status' => 'sent', 'sent_at' => now()]);

    expect($this->lifecycle->followUpSendAtUtc($job->refresh()))->toBeNull();

    $ask = SchedulingAsk::for($job);
    $message = $this->lifecycle->sendManualMessage(
        $job, $ask->template, $this->actor, $ask->vars,
        $this->lifecycle->followUpSendAtUtc($job),
    );

    expect($message->scheduled_for)->toBeNull();
});

it('still holds behind the single-intro welcome flow until it sends', function () {
    $job = askJob($this, '2026-07-24');
    // "Podium exists": one intro, dispatched for immediate delivery (no
    // scheduled_for). Still queued means still in flight, so we wait the gap.
    $this->lifecycle->sendWelcome($job, salesAcknowledged: true, actor: $this->actor);

    expect($this->lifecycle->followUpSendAtUtc($job->refresh())?->timestamp)
        ->toBe(now()->addSeconds(30)->timestamp);
});

it('does not delay a scheduling text on a job that never got a welcome', function () {
    $job = askJob($this, '2026-07-24');

    expect($this->lifecycle->followUpSendAtUtc($job))->toBeNull();
});
