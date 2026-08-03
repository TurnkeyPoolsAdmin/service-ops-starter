<?php

use App\Filament\Pages\Analytics;
use App\Filament\Pages\ReviewCallScoreboard;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\ReviewRequest;
use App\Support\Timezone;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->owner = Employee::create([
        'name' => 'Owner', 'email' => 'owner@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->owner->assignRole('super_admin');

    $this->dispatcher = Employee::create([
        'name' => 'Dee', 'email' => 'dee@test.local', 'active' => true,
        'job_functions' => ['office'],
    ]);

    $this->actingAs($this->owner);
});

/** A ReviewRequest with a call logged N local days ago, for the test dispatcher. */
function loggedCall($test, int $daysAgo, string $outcome, ?string $cancelReason = null): ReviewRequest
{
    $customer = Customer::create(['phone_e164' => '+1818555'.str_pad((string) random_int(1000, 9999), 4, '0')]);
    $entity = Entity::where('code', 'LA')->first();
    $job = Job::create([
        'job_number' => 'LA-2026-'.random_int(10000, 99999),
        'entity_id' => $entity->id,
        'customer_id' => $customer->id,
        'customer_name_snapshot' => 'Someone',
        'status' => 'completed',
    ]);
    $loggedAt = Timezone::now()->subDays($daysAgo)->setTime(12, 0)->utc();

    return ReviewRequest::create([
        'job_id' => $job->id,
        'customer_id' => $customer->id,
        'entity_id' => $entity->id,
        'sentiment' => 'up',
        'status' => $cancelReason ? ReviewRequest::STATUS_CANCELED : ReviewRequest::STATUS_ACTIVE,
        'created_by' => $test->dispatcher->id,
        'call_outcome' => $outcome,
        'call_logged_at' => $loggedAt,
        'call_logged_by' => $test->dispatcher->id,
        'cancel_reason' => $cancelReason,
    ]);
}

it('counts calls logged and their outcomes for the selected window', function () {
    loggedCall($this, 0, ReviewRequest::OUTCOME_CONNECTED);
    loggedCall($this, 0, ReviewRequest::OUTCOME_VOICEMAIL);
    loggedCall($this, 3, ReviewRequest::OUTCOME_CONNECTED, 'not interested (said on call)');
    loggedCall($this, 20, ReviewRequest::OUTCOME_CONNECTED);

    $today = Livewire::test(ReviewCallScoreboard::class, ['range' => 'today'])->assertOk();
    $row = $today->instance()->getTable()->getRecords()->first();
    expect((int) $row->window_calls_count)->toBe(2)
        ->and((int) $row->window_connected_count)->toBe(1)
        ->and((int) $row->window_voicemail_count)->toBe(1);

    $week = Livewire::test(ReviewCallScoreboard::class, ['range' => 'last_7']);
    $row = $week->instance()->getTable()->getRecords()->first();
    expect((int) $row->window_calls_count)->toBe(3)
        ->and((int) $row->window_not_interested_count)->toBe(1);

    $month = Livewire::test(ReviewCallScoreboard::class, ['range' => 'last_30']);
    $row = $month->instance()->getTable()->getRecords()->first();
    expect((int) $row->window_calls_count)->toBe(4);
});

it('scopes yesterday to that day alone', function () {
    loggedCall($this, 0, ReviewRequest::OUTCOME_CONNECTED);
    loggedCall($this, 1, ReviewRequest::OUTCOME_VOICEMAIL);

    $page = Livewire::test(ReviewCallScoreboard::class, ['range' => 'yesterday']);
    $row = $page->instance()->getTable()->getRecords()->first();

    expect((int) $row->window_calls_count)->toBe(1)
        ->and((int) $row->window_voicemail_count)->toBe(1);
});

it('is a tile on the Analytics hub and hidden from non-super-admins', function () {
    loggedCall($this, 0, ReviewRequest::OUTCOME_CONNECTED);

    expect(ReviewCallScoreboard::canAccess())->toBeTrue()
        ->and(collect(Analytics::visibleTiles())->pluck('label'))->toContain('Review Call Scoreboard');

    $dispatcherUser = Employee::create([
        'name' => 'Dee Login', 'email' => 'deelogin@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $dispatcherUser->assignRole('dispatch');
    $this->actingAs($dispatcherUser);

    expect(ReviewCallScoreboard::canAccess())->toBeFalse();
});
