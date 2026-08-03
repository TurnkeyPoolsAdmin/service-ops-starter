<?php

namespace App\Services\Telegram;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\Job;
use App\Support\Money;
use App\Support\Timezone;

/**
 * Builds the weekly Friday digest posted to the Telegram group chat: every job
 * whose payment the Xpro team collected on our behalf (Klarna / SwiftSwipe /
 * Other - Xpro Team) that we still have to collect back from them
 * (Job::scopeOwedFromXpro — the same set the Owed from Xpro finance page shows).
 *
 * build() returns null when nothing is outstanding so the caller can stay quiet
 * rather than posting an empty alert.
 */
class WeeklyXproAlert
{
    public function build(): ?string
    {
        $jobs = Job::owedFromXpro()
            ->with(['entity', 'customer', 'payments'])
            ->orderBy('payment_received_at')
            ->get();

        if ($jobs->isEmpty()) {
            return null;
        }

        $today = Timezone::now()->format('D, M j');
        $total = $jobs->sum(fn (Job $job) => $job->xproOwedCents());

        $lines = [
            "💰 <b>Service Ops — Owed from Xpro</b> ({$today})",
            '',
            'Collect these from the Xpro team ('.$jobs->count().' — '.Money::format($total).'):',
        ];

        foreach ($jobs as $job) {
            $name = $this->jobLink($job);
            $loc = e($job->entity?->code ?? '—');
            $amount = Money::format($job->xproOwedCents());
            $method = e($job->xproMethodsLabel() ?? 'Xpro');
            $lines[] = "• {$name} — {$loc} — {$amount} · {$method}";
        }

        return trim(implode("\n", $lines));
    }

    private function jobLink(Job $job): string
    {
        $name = e($job->customer_name_snapshot ?: 'Unknown');
        $url = JobResource::getUrl('view', ['record' => $job]);

        return "<a href=\"{$url}\">{$name}</a>";
    }
}
