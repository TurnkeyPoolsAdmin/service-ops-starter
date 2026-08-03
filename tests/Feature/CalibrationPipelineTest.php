<?php

use App\Enums\CalibrationStatus;
use App\Enums\PaymentMethod;
use App\Enums\TemplateKey;
use App\Filament\Pages\CalibrationJobs;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Jobs\JobResource;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\ReviewRequest;
use App\Models\Setting;
use App\Services\Calibration\CalibrationService;
use App\Services\JobLifecycle;
use Carbon\Carbon;
use Database\Seeders\EntitySeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    // A fixed weekday morning: Telegram posts nothing on a Sunday
    // (App\Support\AlertWindow), so the partner/ops alerts must pin the day.
    Carbon::setTestNow(Carbon::parse('2026-08-03 09:00', 'America/Los_Angeles')); // Monday

    Queue::fake();
    Mail::fake();
    $this->seed([RoleSeeder::class, EntitySeeder::class, MessageTemplateSeeder::class, SettingSeeder::class]);

    $this->actor = Employee::create([
        'name' => 'Op', 'email' => 'op-cal@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->actor->assignRole('super_admin');

    $this->tech = Employee::create([
        'name' => 'Tech', 'email' => 'tech-cal@test.local', 'active' => true,
        'job_functions' => ['technician'], 'phone' => '+18185550001',
    ]);

    $this->partner = Employee::create([
        'name' => 'the calibration partner', 'email' => 'partner-cal@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('12345'), 'job_functions' => ['office'],
    ]);
    $this->partner->assignRole('calibration');

    $this->lifecycle = app(JobLifecycle::class);
    $this->calibration = app(CalibrationService::class);

    Setting::put('telegram_bot_token', 'test-token', sensitive: true);
    Setting::put('telegram_chat_id', '-1009999');
});

afterEach(fn () => Carbon::setTestNow());

/** Windshield install done on a job flagged for calibration. */
function calInstallDone($test, array $intake = []): Job
{
    $job = $test->lifecycle->createFromIntake(array_merge([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Cal',
        'vehicle_year' => '2020',
        'vehicle_make' => 'Toyota',
        'vehicle_model' => 'Camry',
        'city' => 'Burbank',
        'service_address' => '123 Main St',
        'vin' => '1HGBH41JXMN109186',
        'part_number' => 'FW01234',
        'quote_amount_cents' => 45000,
        'needs_calibration' => true,
        'intake_raw_text' => 'raw',
    ], $intake), $test->actor);

    $test->lifecycle->schedule(
        $job,
        Carbon::parse('2026-07-15 09:00', 'America/Los_Angeles'),
        $test->actor,
        technicianId: $test->tech->id,
    );

    $test->lifecycle->markWorkComplete($job->refresh(), $test->actor);

    return $job->refresh();
}

it('grants the calibration role only manage_calibration', function () {
    expect($this->partner->can('manage_calibration'))->toBeTrue()
        ->and($this->partner->can('manage_jobs'))->toBeFalse()
        ->and($this->partner->can('intake_leads'))->toBeFalse()
        ->and($this->partner->can('use_bank_ledger'))->toBeFalse();
});

it('lets the partner open Calibration but not Jobs; Dashboard is hidden and redirects', function () {
    $this->actingAs($this->partner);

    expect(CalibrationJobs::canAccess())->toBeTrue()
        ->and(JobResource::canAccess())->toBeFalse()
        // canAccess must stay true — false was a hard 403 on /admin after login.
        ->and(Dashboard::canAccess())->toBeTrue()
        ->and(Dashboard::shouldRegisterNavigation())->toBeFalse()
        ->and(Dashboard::isCalibrationOnlyPartner())->toBeTrue();
});

it('lets ops users see the Dashboard in the nav', function () {
    $this->actingAs($this->actor);

    expect(Dashboard::canAccess())->toBeTrue()
        ->and(Dashboard::shouldRegisterNavigation())->toBeTrue()
        ->and(Dashboard::isCalibrationOnlyPartner())->toBeFalse();
});

it('uses the calibration time-confirmation template when scheduling', function () {
    $job = $this->lifecycle->createFromIntake([
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'phone_e164' => '+18185551234',
        'first_name' => 'Cal',
        'needs_calibration' => true,
        'quote_amount_cents' => 45000,
    ], $this->actor);

    $this->lifecycle->schedule(
        $job,
        Carbon::parse('2026-07-15 09:00', 'America/Los_Angeles'),
        $this->actor,
        technicianId: $this->tech->id,
    );

    expect($job->refresh()->scheduledMessages()
        ->where('template_key', TemplateKey::TimeConfirmationCalibration->value)
        ->exists())->toBeTrue()
        ->and($job->scheduledMessages()
            ->where('template_key', TemplateKey::TimeConfirmation->value)
            ->exists())->toBeFalse();
});

it('enters the calibration pipeline on mark work complete and skips review drip', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 11]], 200)]);

    $job = calInstallDone($this);

    expect($job->calibration_status)->toBe(CalibrationStatus::Needed)
        ->and($job->isInCalibrationPipeline())->toBeTrue()
        ->and($job->reviewDeferredForCalibration())->toBeTrue()
        ->and($job->scheduledMessages()
            ->where('template_key', TemplateKey::CalibrationPartnerOutreach->value)
            ->exists())->toBeTrue()
        ->and(ReviewRequest::where('job_id', $job->id)->exists())->toBeFalse();

    // Partner alert goes to Telegram (email is skipped when mailer is log/array).
    Http::assertSent(fn ($req) => str_contains($req->url(), '/bottest-token/sendMessage')
        && str_contains($req['text'], 'Calibration needed'));
});

it('opens no review request on payment while calibration is pending', function () {
    $job = calInstallDone($this);

    $this->lifecycle->recordPayment($job, 45000, PaymentMethod::Zelle, null, null, $this->actor);

    expect(ReviewRequest::where('job_id', $job->id)->exists())->toBeFalse();
});

it('lets the partner schedule and mark complete with PDFs, then ops confirm starts review', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 9]], 200)]);

    $job = calInstallDone($this);

    $this->calibration->schedule(
        $job,
        Carbon::parse('2026-07-18 10:00', 'America/Los_Angeles'),
        $this->partner,
    );

    expect($job->refresh()->calibration_status)->toBe(CalibrationStatus::Scheduled)
        ->and($job->calibration_scheduled_by)->toBe($this->partner->id);

    $this->calibration->markComplete(
        $job->refresh(),
        'calibration/'.$job->id.'/invoice.pdf',
        'calibration/'.$job->id.'/report.pdf',
        $this->partner,
    );

    $job->refresh();
    expect($job->calibration_status)->toBe(CalibrationStatus::Completed)
        ->and($job->calibration_invoice_path)->toBe('calibration/'.$job->id.'/invoice.pdf')
        ->and($job->calibration_report_path)->toBe('calibration/'.$job->id.'/report.pdf')
        ->and($job->isCalibrationConfirmed())->toBeFalse();

    Http::assertSent(fn ($req) => str_contains($req->url(), '/bottest-token/sendMessage')
        && str_contains($req['text'], 'Calibration completed'));

    $this->calibration->confirm($job->refresh(), wentWell: true, actor: $this->actor);

    $job->refresh();
    expect($job->isCalibrationConfirmed())->toBeTrue()
        ->and($job->calibration_went_well)->toBeTrue()
        ->and($job->reviewDeferredForCalibration())->toBeFalse()
        ->and($job->scheduledMessages()
            ->where('template_key', TemplateKey::CalibrationCompletedConfirm->value)
            ->exists())->toBeTrue()
        ->and(ReviewRequest::where('job_id', $job->id)->where('status', ReviewRequest::STATUS_AWAITING_CALL)->exists())
        ->toBeTrue();
});

it('requires both PDFs to mark calibration complete', function () {
    $job = calInstallDone($this);

    expect(fn () => $this->calibration->markComplete($job, '', 'report.pdf', $this->partner))
        ->toThrow(RuntimeException::class);
});

it('lists pipeline jobs on the Calibration page for the partner', function () {
    $job = calInstallDone($this);

    $this->actingAs($this->partner);

    expect(CalibrationJobs::canAccess())->toBeTrue();

    Livewire::actingAs($this->partner)
        ->test(CalibrationJobs::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$job]);
});
