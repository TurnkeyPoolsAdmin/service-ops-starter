<?php

namespace App\Services\Telegram;

use App\Filament\Pages\CalibrationJobs;
use App\Models\Job;

/**
 * Telegram alert when the calibration partner marks a calibration complete (invoice + report
 * uploaded). Ops then logs in, thumbs up/down, and the review drip starts.
 */
class CalibrationCompletedAlert
{
    public function build(Job $job): ?string
    {
        $job->loadMissing('entity');

        $name = e($job->customer_name_snapshot ?: 'Unknown');
        $url = CalibrationJobs::getUrl();
        $link = "<a href=\"{$url}\">{$name}</a>";
        $loc = e($job->entity?->code ?? '—');
        $vehicle = e($job->vehicleLabel() ?: '—');
        $jobNum = e($job->job_number ?? '');

        return trim(implode("\n", [
            '✅ <b>Service Ops — Calibration completed</b>',
            '',
            "• {$link} — {$loc} · {$vehicle}",
            $jobNum !== '' ? "  Job {$jobNum}" : null,
            '',
            'Invoice + calibration report uploaded. Open Calibration → Awaiting confirm to thumbs up/down and start the review texts.',
        ]));
    }
}
