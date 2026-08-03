<?php

namespace App\Services\Telegram;

use App\Filament\Pages\CalibrationJobs;
use App\Models\Job;

/**
 * Telegram alert when a job enters the calibration pipeline (windshield install
 * just marked complete). Same summary the partner email carries — name, vehicle,
 * city, address, VIN, part # — so ops/the calibration partner see it even when outbound mail is
 * not configured (prod currently uses MAIL_MAILER=log).
 */
class CalibrationNeededAlert
{
    public function build(Job $job): ?string
    {
        $job->loadMissing('entity');

        $name = e($job->customer_name_snapshot ?: 'Unknown');
        $url = CalibrationJobs::getUrl();
        $link = "<a href=\"{$url}\">{$name}</a>";
        $loc = e($job->entity?->code ?? '—');
        $vehicle = e($job->vehicleLabel() ?: '—');
        $city = e($job->city ?: '—');
        $address = e($job->service_address ?: '—');
        $vin = e($job->vin ?: '—');
        $part = e($job->part_number ?: '—');
        $jobNum = e($job->job_number ?? '');

        return trim(implode("\n", array_filter([
            '🔧 <b>Service Ops — Calibration needed</b>',
            '',
            "• {$link} — {$loc} · {$vehicle}",
            $jobNum !== '' ? "  Job {$jobNum}" : null,
            "  City: {$city}",
            "  Address: {$address}",
            "  VIN: {$vin}",
            "  WS Part #: {$part}",
            '',
            'Partner should schedule the ADAS visit. Open Calibration to view / act.',
        ])));
    }
}
