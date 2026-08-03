<?php

namespace App\Services\Telegram;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\ReviewRequest;
use App\Support\AlertWindow;
use App\Support\Timezone;

/**
 * Builds the "call this customer for a review" nag posted to the Telegram
 * group chat. Every job's review request opens awaiting_call the instant work
 * is marked complete (or, for a calibration job, once ops confirms
 * calibration) — dispatch is prompted right then in-app, but this is the
 * backstop: it reposts the live list every hour
 * (alerts:telegram-review-calls) so a call never gets forgotten, and a job
 * drops off the moment the first call is logged or the 7pm deadline auto-
 * falls-through. No per-row stamp — it's a live digest, same shape as
 * SameDayPartsUnorderedAlert.
 *
 * build() returns null when nothing is outstanding, or when called outside
 * App\Support\AlertWindow (no point nagging the group about a phone call at
 * 2am). Safe to gate: the list is rebuilt live each run, so a suppressed run
 * simply reposts after 7am.
 */
class ReviewCallReminderAlert
{
    public function build(): ?string
    {
        if (! AlertWindow::open()) {
            return null;
        }

        $rows = ReviewRequest::query()
            ->awaitingFirstCall()
            ->with(['job.entity', 'job.customer'])
            ->get()
            ->filter(fn (ReviewRequest $r) => $r->job !== null);

        if ($rows->isEmpty()) {
            return null;
        }

        $now = Timezone::now()->format('D, M j g:ia');

        $lines = [
            "⭐ <b>Service Ops — Review call needed</b> ({$now})",
            '',
            'Call these customers for a review ('.$rows->count().'):',
        ];

        foreach ($rows as $request) {
            $lines[] = '• '.$this->rowLine($request);
        }

        return trim(implode("\n", $lines));
    }

    private function rowLine(ReviewRequest $request): string
    {
        $job = $request->job;
        $name = e($job->customer_name_snapshot ?: 'Unknown');
        $url = JobResource::getUrl('view', ['record' => $job]);
        $loc = e($job->entity?->code ?? '—');
        $jobNumber = e($job->job_number ?? '—');
        $skipped = $request->first_call_skipped_at !== null ? ' (skipped for later)' : '';

        return "<a href=\"{$url}\">{$name}</a> — {$jobNumber} — {$loc}{$skipped}";
    }
}
