<?php

namespace App\Services\Telegram;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\WarrantyClaim;
use App\Support\Timezone;

/**
 * Builds the "warranty claims still waiting to be scheduled" nag posted to the
 * Telegram group chat: every OPEN (not yet booked) return visit. Posted on a
 * recurring cadence so a filed warranty keeps surfacing until someone schedules
 * the visit — at which point it drops off the list.
 *
 * build() returns null when nothing is unscheduled so the caller stays quiet.
 */
class UnscheduledWarrantyAlert
{
    public function build(): ?string
    {
        $claims = WarrantyClaim::needsScheduling()
            ->with(['job', 'entity', 'technician'])
            ->orderBy('created_at')
            ->get();

        if ($claims->isEmpty()) {
            return null;
        }

        $today = Timezone::now()->format('D, M j');

        $lines = [
            "🛡️ <b>Service Ops — Warranty visits to schedule</b> ({$today})",
            '',
            'Filed but not yet booked ('.$claims->count().'):',
        ];

        foreach ($claims as $claim) {
            $link = $this->claimLink($claim);
            $loc = e($claim->entity?->code ?? '—');
            $issue = e(str($claim->issue)->limit(80));
            $filed = Timezone::toDisplay($claim->created_at)?->format('M j');
            $filedStr = $filed ? ' · filed '.e($filed) : '';
            $lines[] = "• {$link} — {$loc} — {$issue}{$filedStr}";
        }

        return trim(implode("\n", $lines));
    }

    private function claimLink(WarrantyClaim $claim): string
    {
        $name = e($claim->job?->customer_name_snapshot ?: 'Unknown');

        if ($claim->job === null) {
            return $name;
        }

        $url = JobResource::getUrl('view', ['record' => $claim->job]);

        return "<a href=\"{$url}\">{$name}</a>";
    }
}
