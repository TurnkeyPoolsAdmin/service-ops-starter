<?php

namespace App\Services\Telegram;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\WarrantyClaim;
use App\Services\Messaging\TemplateRenderer;
use App\Support\AlertWindow;
use App\Support\Timezone;
use Illuminate\Support\Collection;

/**
 * The warranty analogue of OverdueCompletionAlert, and it follows that class in
 * every respect: pings the group when a SCHEDULED return visit hasn't been marked
 * complete (resolved) a grace period past its arrival window — e.g. an 8am-10am
 * visit still open at 11am with the default 1h grace.
 *
 * ⚠️ REPEATS every `overdue_repeat_hours` (1h) until the visit is resolved, and
 * is silent outside App\Support\AlertWindow (7am-7pm local).
 * overdue_completion_alerted_at means "when we last said something"; scheduling
 * nulls it so a pushed-back visit starts its clock over.
 */
class WarrantyOverdueCompletionAlert
{
    /** Scheduled visits past window + grace, unresolved, due to alert again. */
    public function dueClaims(): Collection
    {
        if (! AlertWindow::open()) {
            return collect();
        }

        $grace = $this->graceHours();
        $repeatBefore = now()->subHours($this->repeatHours());

        return WarrantyClaim::query()
            ->where('status', WarrantyClaim::STATUS_SCHEDULED)
            ->where(fn ($q) => $q->whereNull('overdue_completion_alerted_at')
                ->orWhere('overdue_completion_alerted_at', '<=', $repeatBefore))
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now()->subHours($grace))
            ->with(['job', 'entity', 'technician'])
            ->orderBy('scheduled_at')
            ->get()
            ->filter(fn (WarrantyClaim $c) => now()->gte($c->scheduledWindowEndUtc()->copy()->addHours($grace)))
            ->values();
    }

    public function render(Collection $claims): string
    {
        $grace = $this->graceHours();
        $today = Timezone::now()->format('D, M j');

        $lines = [
            "⏰ <b>Service Ops — Warranty visit overdue</b> ({$today})",
            '',
            "Return visits not marked complete {$grace}h+ after the window (".$claims->count().'):',
        ];

        foreach ($claims as $claim) {
            $link = $this->claimLink($claim);
            $loc = e($claim->entity?->code ?? '—');
            $day = Timezone::toDisplay($claim->scheduled_at)?->format('D');
            $window = TemplateRenderer::formatVisit(
                $claim->scheduled_at, (bool) $claim->scheduled_exact_time, $claim->scheduled_window_hours,
            );
            $whenStr = $window ? ' — '.e(trim($day.' '.$window)) : '';
            $tech = filled($claim->technician?->name) ? ' · '.e($claim->technician->name) : '';
            $lines[] = "• {$link} — {$loc}{$whenStr}{$tech}";
        }

        return trim(implode("\n", $lines));
    }

    private function graceHours(): int
    {
        return (int) config('glass.telegram.overdue_grace_hours', 1);
    }

    private function repeatHours(): int
    {
        return (int) config('glass.telegram.overdue_repeat_hours', 1);
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
