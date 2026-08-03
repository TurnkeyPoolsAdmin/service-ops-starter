<?php

use App\Enums\Language;
use App\Enums\TimePreference;
use App\Filament\Resources\Jobs\Schemas\JobForm;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\Job;
use App\Services\AiScheduling\RequestedSlotParser;
use App\Services\AiScheduling\SchedulingConstraint;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Parsing\WhatsAppLeadParser;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;

/**
 * "After 10am" — the requested-time preference that carries its own hour
 * (TimePreference::After + jobs.requested_after_time) from intake through the
 * SMS copy and into the AI scheduler's negotiation window.
 */
beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);
    Carbon::setTestNow(Carbon::parse('2026-07-20 09:00', 'America/Los_Angeles')); // a Monday
});

afterEach(fn () => Carbon::setTestNow());

function afterJob(?string $requestedDay, ?string $afterTime, ?string $requestedTime = null): Job
{
    $customer = Customer::create(['phone_e164' => '+1818555'.random_int(1000, 9999)]);

    return Job::create([
        'job_number' => 'LA-2026-'.random_int(10000, 99999),
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customer->id,
        'requested_day' => $requestedDay,
        'requested_time_preference' => TimePreference::After->value,
        'requested_after_time' => $afterTime,
        'requested_time' => $requestedTime,
    ]);
}

// ---- the enum ----

it('reads an after request out of free text, ahead of the ASAP words', function () {
    expect(TimePreference::normalize('after 10am'))->toBe(TimePreference::After)
        ->and(TimePreference::normalize('anytime after 3pm'))->toBe(TimePreference::After)
        ->and(TimePreference::normalize('después de las 2'))->toBe(TimePreference::After)
        // No clock time in it at all - "after" alone says nothing schedulable.
        ->and(TimePreference::normalize('after lunch'))->toBeNull()
        ->and(TimePreference::normalize('2pm'))->toBe(TimePreference::Specific);
});

it('snaps a free-text after hour onto the half-hour option grid', function () {
    expect(TimePreference::afterTimeFrom('after 10am'))->toBe('10:00')
        ->and(TimePreference::afterTimeFrom('after 10:15am'))->toBe('10:00')
        ->and(TimePreference::afterTimeFrom('after 10:45am'))->toBe('10:30')
        // A bare 1-6 is the afternoon; a bare 7-11 stays the morning.
        ->and(TimePreference::afterTimeFrom('anytime after 3'))->toBe('15:00')
        ->and(TimePreference::afterTimeFrom('after 8'))->toBe('08:00')
        // Outside the working day, clamped onto it rather than dropped.
        ->and(TimePreference::afterTimeFrom('after 5am'))->toBe('07:00')
        ->and(TimePreference::afterTimeFrom('whenever'))->toBeNull();
});

it('words the after preference for a human and for a customer text', function () {
    expect(TimePreference::After->label())->toBe('After a time')
        ->and(TimePreference::After->shortLabel('10:00'))->toBe('After 10am')
        ->and(TimePreference::After->shortLabel('16:30'))->toBe('After 4:30pm')
        // No hour stored: never render a dangling "After".
        ->and(TimePreference::After->shortLabel(null))->toBe('After')
        ->and(TimePreference::After->phrase(null, Language::English, '10:00'))->toBe('after 10am')
        ->and(TimePreference::After->phrase(null, Language::Spanish, '10:00'))->toBe('después de las 10am')
        // A known preference, so the scheduling ask acknowledges instead of asking.
        ->and(TimePreference::After->isKnown())->toBeTrue();
});

it('offers only working-day half hours as after options', function () {
    $options = TimePreference::afterTimeOptions();

    expect(array_key_first($options))->toBe('07:00')
        ->and(array_key_last($options))->toBe('18:00')
        ->and($options['10:00'])->toBe('10am')
        ->and($options['12:30'])->toBe('12:30pm')
        ->and($options)->toHaveCount(23);
});

// ---- the job ----

it('builds the requested day/time string from the stored hour', function () {
    expect(JobForm::syncRequestedDaytime([
        'requested_day' => '2026-07-27',
        'requested_time_preference' => TimePreference::After->value,
        'requested_after_time' => '10:00',
    ]))->toMatchArray(['requested_daytime_raw' => 'Monday, July 27 After 10am']);
});

it('clears the stored hour when the preference moves off after', function () {
    $data = JobForm::syncRequestedDaytime([
        'requested_day' => '2026-07-27',
        'requested_time_preference' => TimePreference::Morning->value,
        'requested_after_time' => '10:00',
    ]);

    expect($data['requested_after_time'])->toBeNull()
        ->and($data['requested_daytime_raw'])->toBe('Monday, July 27 Morning');
});

it('merges the after wording into the {requested_time} template variable', function () {
    $job = afterJob('2026-07-27', '15:00');

    expect(app(TemplateRenderer::class)->varsForJob($job)['requested_time'])->toBe('after 3pm');
});

it('reads a legacy after job through its free text', function () {
    // No structured hour (a job taken before the column existed).
    $job = afterJob('2026-07-27', null, 'after 10am');

    expect($job->requestedAfterTime())->toBe('10:00')
        ->and($job->requestedTimeLabel())->toBe('After 10am');
});

// ---- the parser ----

it('parses a pasted "after" request into the preference plus its hour', function () {
    $lead = (new WhatsAppLeadParser)->parse("Name: Ana Diaz\nPhone: 818-555-0111\nReq. Day/Time: Tomorrow after 10am");

    expect($lead->requested_day)->toBe('Tomorrow')
        ->and($lead->requested_time)->toBe('After 10am')
        ->and($lead->requested_time_preference)->toBe(TimePreference::After->value)
        ->and($lead->requested_after_time)->toBe('10:00');
});

it('keeps a plain clock time a specific request', function () {
    $lead = (new WhatsAppLeadParser)->parse("Name: Ana Diaz\nPhone: 818-555-0111\nReq. Day/Time: Tomorrow 10am");

    expect($lead->requested_time_preference)->toBe(TimePreference::Specific->value)
        ->and($lead->requested_after_time)->toBeNull();
});

it('reads an "after" constraint the rep wrote in the notes instead of on a day/time line', function () {
    $lead = (new WhatsAppLeadParser)->parse(
        "Name: Ursula Quinn\nPhone: 818-555-0111\nNotes: MUST Call 30 mins before\nNote: Cust only available after 2:30pm"
    );

    expect($lead->requested_time_preference)->toBe(TimePreference::After->value)
        ->and($lead->requested_after_time)->toBe('14:30')
        ->and($lead->requested_time)->toBe('After 2:30pm')
        // Read out of prose, so the operator is told to check it.
        ->and($lead->warnings)->toContain('Requested time read from the notes: after 2:30pm. Check it before you confirm.');
});

it('lets an explicit requested time beat the notes', function () {
    $lead = (new WhatsAppLeadParser)->parse(
        "Name: Ana Diaz\nPhone: 818-555-0111\nReq. Day/Time: Tomorrow 10am\nNotes: cust called after 4pm yesterday"
    );

    expect($lead->requested_time_preference)->toBe(TimePreference::Specific->value)
        ->and($lead->requested_after_time)->toBeNull();
});

it('never reads an hour out of a note that has no clock time', function () {
    $notes = [
        'MUST Call 30 mins before',          // "before", not "after"
        'call after 30 mins',                // a duration, not a time of day
        'install after we pick up the part', // no number at all
        'cust only available in the morning', // a day part is not safe to claim from prose
    ];

    foreach ($notes as $note) {
        $lead = (new WhatsAppLeadParser)->parse("Name: Ana Diaz\nPhone: 818-555-0111\nNotes: {$note}");

        expect($lead->requested_time_preference)->toBeNull()
            ->and($lead->requested_after_time)->toBeNull();
    }
});

// ---- the AI scheduler ----

it('parses an after request into a lower-bound constraint', function () {
    $constraint = (new RequestedSlotParser)->parse(afterJob('2026-07-22', '10:00'));

    expect($constraint->firmness)->toBe(SchedulingConstraint::FIRM_AFTER)
        ->and($constraint->earliest)->toBe('10:00')
        // To close, not to the end of the morning.
        ->and($constraint->latest)->toBe('19:00')
        ->and($constraint->partOfDay)->toBe('any')
        ->and($constraint->exactTime)->toBeNull()
        // Given the whole window we still offer the earliest slot in it.
        ->and($constraint->preferredTime())->toBe('10:00')
        ->and($constraint->needsSlotNarrowing())->toBeTrue();
});

it('admits only slots at or after the requested hour', function () {
    $constraint = (new RequestedSlotParser)->parse(afterJob('2026-07-22', '10:00'));

    expect($constraint->admits('2026-07-22', '09:00'))->toBeFalse()
        ->and($constraint->admits('2026-07-22', '10:00'))->toBeTrue()
        ->and($constraint->admits('2026-07-22', '16:00'))->toBeTrue()
        // The date never moves during a tech negotiation.
        ->and($constraint->admits('2026-07-23', '11:00'))->toBeFalse()
        // Nobody named a time - the constraint's own preferredTime() books it.
        ->and($constraint->admits('2026-07-22', null))->toBeTrue();
});

it('phrases the after constraint for techs in both languages', function () {
    $constraint = (new RequestedSlotParser)->parse(afterJob('2026-07-22', '15:00'));

    expect($constraint->phrase(Language::English))->toBe('Wednesday, July 22 after 3pm')
        ->and($constraint->shortPhrase(Language::English))->toBe('after 3pm')
        ->and($constraint->shortPhrase(Language::Spanish))->toBe('después de las 3pm')
        // The booked slot is still the ordinary 2-hour arrival window.
        ->and($constraint->bookedPhrase('15:00', Language::English))->toBe('Wednesday, July 22 from 3pm to 5pm');
});

it('survives the session round trip as JSON', function () {
    $constraint = (new RequestedSlotParser)->parse(afterJob('2026-07-22', '10:00'));
    $restored = SchedulingConstraint::fromArray($constraint->toArray());

    expect($restored->firmness)->toBe(SchedulingConstraint::FIRM_AFTER)
        ->and($restored->earliest)->toBe('10:00')
        ->and($restored->latest)->toBe('19:00');
});

it('falls back to a flexible day when an after job never got its hour', function () {
    $constraint = (new RequestedSlotParser)->parse(afterJob('2026-07-22', null, 'sometime later'));

    expect($constraint->firmness)->toBe(SchedulingConstraint::FIRM_FLEXIBLE);
});
