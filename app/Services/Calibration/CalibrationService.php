<?php

namespace App\Services\Calibration;

use App\Enums\CalibrationStatus;
use App\Enums\TemplateKey;
use App\Filament\Pages\CalibrationJobs;
use App\Jobs\SendSmsJob;
use App\Models\Employee;
use App\Models\Job;
use App\Models\ScheduledMessage;
use App\Services\Mail\ResendMailer;
use App\Services\Messaging\OutboxService;
use App\Services\Reviews\ReviewDripService;
use App\Services\Telegram\CalibrationCompletedAlert;
use App\Services\Telegram\CalibrationNeededAlert;
use App\Services\Telegram\TelegramNotifier;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADAS calibration partner flow (the calibration partner), start to finish:
 *
 *   windshield install marked complete (job needsCalibration)
 *     → status = needed; second SMS ("partner will reach out");
 *       Telegram + partner email via Resend (Admin → Integrations)
 *     → partner schedules visit  → status = scheduled
 *     → partner marks complete + uploads invoice PDF + report PDF
 *         → status = completed; Telegram to ops
 *     → ops thumbs up/down (calibration's own outcome)
 *         → final SMS confirming calibration; the review call-first queue
 *           opens either way (ReviewDripService::startAwaitingCall)
 *
 * The review queue is deferred until AFTER calibration — never at install
 * complete / payment for these jobs (see Job::reviewDeferredForCalibration).
 */
class CalibrationService
{
    public function __construct(private OutboxService $outbox) {}

    /**
     * Enter the calibration pipeline once the windshield install is marked
     * complete. Idempotent: a job already in the pipeline is left alone.
     * Returns the "partner will reach out" text row when queued, else null.
     */
    public function enterAfterInstall(Job $job, Employee $actor): ?ScheduledMessage
    {
        if (! $job->needsCalibration()) {
            return null;
        }

        if ($job->calibration_status !== null) {
            return null;
        }

        if (! $job->isWorkComplete()) {
            throw new \RuntimeException('Calibration starts only after the windshield install is marked complete.');
        }

        // Persist status + queue SMS inside the transaction; network I/O
        // (Telegram / email) runs after so a mail outage never rolls back entry.
        $message = DB::transaction(function () use ($job, $actor) {
            $job->calibration_status = CalibrationStatus::Needed;
            $job->save();

            activity()->performedOn($job)->causedBy($actor)
                ->event('calibration_needed')
                ->log('Calibration needed — partner will schedule');

            $message = $this->outbox->queueForJob(
                $job,
                TemplateKey::CalibrationPartnerOutreach,
                null,
                $actor->id,
            );
            if ($message !== null) {
                $this->dispatchNow($message);
            }

            return $message;
        });

        // markWorkComplete wraps this in its own transaction — wait until that
        // commits before Telegram/email so a rolled-back entry never alerts.
        $jobId = $job->id;
        DB::afterCommit(function () use ($jobId) {
            $fresh = Job::find($jobId);
            if ($fresh !== null) {
                $this->alertPartners($fresh);
            }
        });

        return $message;
    }

    /**
     * Partner (or ops) books the calibration visit with the customer. No
     * customer SMS here — the calibration partner reaches out directly.
     */
    public function schedule(Job $job, CarbonInterface $scheduledAtUtc, Employee $actor): Job
    {
        $this->assertInPipeline($job);

        if ($job->calibration_status === CalibrationStatus::Completed) {
            throw new \RuntimeException('This calibration is already completed.');
        }

        return DB::transaction(function () use ($job, $scheduledAtUtc, $actor) {
            $job->calibration_status = CalibrationStatus::Scheduled;
            $job->calibration_scheduled_at = $scheduledAtUtc->copy()->utc();
            $job->calibration_scheduled_by = $actor->id;
            $job->save();

            activity()->performedOn($job)->causedBy($actor)
                ->event('calibration_scheduled')
                ->log('Calibration scheduled');

            return $job->refresh();
        });
    }

    /**
     * Partner marks the calibration visit complete. Requires both PDFs
     * (invoice + report). Posts a Telegram alert for ops to thumbs-up/down.
     *
     * @param  string  $invoicePath  storage path on the local disk
     * @param  string  $reportPath  storage path on the local disk
     */
    public function markComplete(Job $job, string $invoicePath, string $reportPath, Employee $actor): Job
    {
        $this->assertInPipeline($job);

        if ($job->calibration_status === CalibrationStatus::Completed
            && $job->calibration_completed_at !== null) {
            throw new \RuntimeException('This calibration is already marked complete.');
        }

        if (blank($invoicePath) || blank($reportPath)) {
            throw new \RuntimeException('Both the invoice PDF and the calibration report PDF are required.');
        }

        return DB::transaction(function () use ($job, $invoicePath, $reportPath, $actor) {
            $job->calibration_status = CalibrationStatus::Completed;
            $job->calibration_completed_at = now();
            $job->calibration_completed_by = $actor->id;
            $job->calibration_invoice_path = $invoicePath;
            $job->calibration_report_path = $reportPath;
            $job->save();

            activity()->performedOn($job)->causedBy($actor)
                ->event('calibration_completed')
                ->log('Calibration marked complete (invoice + report uploaded)');

            $this->alertOpsCompleted($job);

            return $job->refresh();
        });
    }

    /**
     * Ops confirms how calibration went (👍/👎). Sends the final customer SMS
     * and — regardless of that thumb, calibration's own outcome is a separate
     * fact from the review flow — opens the call-first review queue, since a
     * calibration job's queue never opened at install/payment.
     */
    public function confirm(Job $job, bool $wentWell, Employee $actor): Job
    {
        $this->assertInPipeline($job);

        if ($job->calibration_status !== CalibrationStatus::Completed) {
            throw new \RuntimeException('Confirm only after the partner marks calibration complete.');
        }

        if ($job->isCalibrationConfirmed()) {
            throw new \RuntimeException('This calibration was already confirmed.');
        }

        return DB::transaction(function () use ($job, $wentWell, $actor) {
            $job->calibration_confirmed_at = now();
            $job->calibration_confirmed_by = $actor->id;
            $job->calibration_went_well = $wentWell;
            $job->save();

            activity()->performedOn($job)->causedBy($actor)
                ->event($wentWell ? 'calibration_confirmed_up' : 'calibration_confirmed_down')
                ->log($wentWell
                    ? 'Calibration confirmed (went well)'
                    : 'Calibration confirmed (not great)');

            $message = $this->outbox->queueForJob(
                $job,
                TemplateKey::CalibrationCompletedConfirm,
                null,
                $actor->id,
            );
            if ($message !== null) {
                $this->dispatchNow($message);
            }

            // The review call-first queue opens here (not at install complete /
            // payment) — dispatch is prompted to call, same as any other job.
            app(ReviewDripService::class)->startAwaitingCall($job, $actor);

            return $job->refresh();
        });
    }

    /**
     * Notify ops + calibration partners that a job needs ADAS scheduling.
     *
     * 1. Telegram — always attempted (same group as other ops alerts).
     * 2. Email via Resend — when Admin → Integrations has a Resend API key +
     *    from address, emails every active calibration-role login. Failures are
     *    logged, never thrown — the job stays in the partner queue either way.
     */
    private function alertPartners(Job $job): void
    {
        $job->loadMissing('entity');

        // Telegram first — works with the existing bot token / chat id.
        $telegramText = app(CalibrationNeededAlert::class)->build($job);
        if ($telegramText !== null) {
            $result = app(TelegramNotifier::class)->send($telegramText);
            // A silent day (Sunday) held the post on purpose — not a failure.
            if (! $result->success && ! $result->suppressed) {
                Log::warning('CalibrationService: Telegram needed alert failed.', [
                    'job_id' => $job->id,
                    'error' => $result->errorMessage ?? $result->errorCode ?? 'unknown',
                ]);
            }
        }

        $partners = Employee::query()
            ->role('calibration')
            ->where('can_login', true)
            ->where('active', true)
            ->whereNotNull('email')
            ->get();

        if ($partners->isEmpty()) {
            Log::warning('CalibrationService: no active calibration-role partners to email.', [
                'job_id' => $job->id,
            ]);

            return;
        }

        $resend = app(ResendMailer::class);
        if (! $resend->configured()) {
            Log::warning('CalibrationService: partner email skipped — Resend not configured (Admin → Integrations → Email / Resend). Telegram alert was attempted.', [
                'job_id' => $job->id,
                'partners' => $partners->pluck('email')->all(),
            ]);

            return;
        }

        $url = CalibrationJobs::getUrl();
        $vehicle = $job->vehicleLabel();
        $subject = 'New calibration job — '.$job->customer_name_snapshot;
        $body = implode("\n", array_filter([
            'A job is ready for ADAS calibration.',
            '',
            'Name: '.$job->customer_name_snapshot,
            'Vehicle: '.($vehicle ?: '—'),
            'City: '.($job->city ?: '—'),
            'Address: '.($job->service_address ?: '—'),
            'VIN: '.($job->vin ?: '—'),
            'WS Part #: '.($job->part_number ?: '—'),
            'Location: '.($job->entity?->code ?? '—'),
            'Job: '.$job->job_number,
            '',
            'Log in to the Calibration section: '.$url,
        ]));

        foreach ($partners as $partner) {
            $result = $resend->send($partner->email, $subject, $body);
            if ($result['ok']) {
                Log::info('CalibrationService: partner email sent via Resend.', [
                    'employee_id' => $partner->id,
                    'email' => $partner->email,
                    'job_id' => $job->id,
                    'resend_id' => $result['id'],
                ]);
            } else {
                Log::warning('CalibrationService: partner email failed.', [
                    'employee_id' => $partner->id,
                    'job_id' => $job->id,
                    'error' => $result['error'],
                ]);
            }
        }
    }

    private function alertOpsCompleted(Job $job): void
    {
        $text = app(CalibrationCompletedAlert::class)->build($job);
        if ($text === null) {
            return;
        }

        $result = app(TelegramNotifier::class)->send($text);
        // A silent day (Sunday) held the post on purpose — not a failure.
        if (! $result->success && ! $result->suppressed) {
            Log::warning('CalibrationService: Telegram completed alert failed.', [
                'job_id' => $job->id,
                'error' => $result->errorMessage ?? $result->errorCode ?? 'unknown',
            ]);
        }
    }

    private function assertInPipeline(Job $job): void
    {
        if ($job->calibration_status === null) {
            throw new \RuntimeException('This job is not in the calibration pipeline.');
        }
    }

    private function dispatchNow(?ScheduledMessage $message): void
    {
        if ($message === null) {
            return;
        }

        SendSmsJob::dispatch($message->id)->afterCommit();
    }
}
