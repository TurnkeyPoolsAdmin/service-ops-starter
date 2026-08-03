<?php

use App\Enums\PaymentMethod;
use App\Filament\Pages\OwedFromXpro;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    // A fixed weekday morning: Telegram posts nothing on a Sunday
    // (App\Support\AlertWindow), so a test asserting a digest must pin the day.
    Carbon::setTestNow(Carbon::parse('2026-08-03 09:00', 'America/Los_Angeles')); // Monday

    Queue::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

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

function xproJob($test, PaymentMethod $method = PaymentMethod::KlarnaXpro, string $name = 'Alan'): Job
{
    $job = $test->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => $name,
        'quote_amount_cents' => 45000,
    ], $test->actor);

    $test->lifecycle->schedule(
        $job,
        Carbon::parse('2026-07-08 09:00', 'America/Los_Angeles'),
        $test->actor,
        technicianId: $test->tech->id,
    );

    $job->refresh()->update(['work_completed_at' => now()]);
    $test->lifecycle->recordPayment($job->refresh(), 45000, $method, null, null, $test->actor);

    return $job->refresh();
}

it('has the three Xpro-collected payment methods with the expected labels', function () {
    expect(PaymentMethod::KlarnaXpro->label())->toBe('Klarna - Xpro Team')
        ->and(PaymentMethod::SwiftSwipeXpro->label())->toBe('SwiftSwipe (CC) - Xpro Team')
        ->and(PaymentMethod::OtherXpro->label())->toBe('Other - Xpro Team')
        ->and(PaymentMethod::KlarnaXpro->isXproCollected())->toBeTrue()
        ->and(PaymentMethod::Zelle->isXproCollected())->toBeFalse()
        ->and(PaymentMethod::options())->toHaveKeys(['klarna_xpro', 'swiftswipe_xpro', 'other_xpro']);
});

it('lists an Xpro-collected job as owed from Xpro, not in AR', function () {
    $job = xproJob($this);

    expect($job->isOwedFromXpro())->toBeTrue()
        ->and(Job::owedFromXpro()->pluck('id'))->toContain($job->id)
        // Kept out of the Accounts Receivable collected-unconfirmed bucket.
        ->and(Job::collectedUnconfirmed()->pluck('id'))->not->toContain($job->id);
})->with([
    PaymentMethod::KlarnaXpro,
    PaymentMethod::SwiftSwipeXpro,
    PaymentMethod::OtherXpro,
]);

it('does not touch the tech cash balance for an Xpro method', function () {
    xproJob($this, PaymentMethod::SwiftSwipeXpro);

    expect($this->tech->refresh()->cash_balance_cents)->toBe(0);
});

it('clears the job from owed-from-Xpro once marked collected', function () {
    $job = xproJob($this);

    $this->lifecycle->markXproCollected($job, $this->actor);
    $job->refresh();

    expect($job->xpro_collected_at)->not->toBeNull()
        ->and($job->xpro_collected_by)->toBe($this->actor->id)
        ->and($job->isOwedFromXpro())->toBeFalse()
        ->and(Job::owedFromXpro()->pluck('id'))->not->toContain($job->id);
});

it('markXproCollected is idempotent and a no-op on non-Xpro jobs', function () {
    $job = xproJob($this);
    $this->lifecycle->markXproCollected($job, $this->actor);
    $first = $job->refresh()->xpro_collected_at;
    $this->lifecycle->markXproCollected($job, $this->actor);
    expect($job->refresh()->xpro_collected_at->eq($first))->toBeTrue();

    // A Zelle job is never owed from Xpro, so marking it is a no-op.
    $zelle = xproJob($this, PaymentMethod::Zelle, 'Zed');
    $this->lifecycle->markXproCollected($zelle, $this->actor);
    expect($zelle->refresh()->xpro_collected_at)->toBeNull();
});

it('renders the page and marks a job collected via the UI', function () {
    $job = xproJob($this);

    Livewire::test(OwedFromXpro::class)
        ->assertCanSeeTableRecords([$job])
        ->callTableAction('markXproCollected', $job);

    expect($job->refresh()->isOwedFromXpro())->toBeFalse();
});

it('posts the weekly Owed-from-Xpro digest to Telegram', function () {
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 7]], 200)]);

    xproJob($this, PaymentMethod::KlarnaXpro, 'Alan');

    $this->artisan('alerts:telegram-xpro-weekly')->assertSuccessful();

    Http::assertSent(function ($req) {
        return str_contains($req->url(), '/bottest-token/sendMessage')
            && $req['chat_id'] === '-1009999'
            && str_contains($req['text'], 'Owed from Xpro')
            && str_contains($req['text'], 'Alan')
            && str_contains($req['text'], 'Klarna');
    });
});

it('stays quiet on the weekly digest when nothing is owed from Xpro', function () {
    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
    Http::fake();

    $this->artisan('alerts:telegram-xpro-weekly')->assertSuccessful();

    Http::assertNothingSent();
});
