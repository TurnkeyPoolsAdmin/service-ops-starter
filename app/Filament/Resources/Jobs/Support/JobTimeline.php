<?php

namespace App\Filament\Resources\Jobs\Support;

use App\Models\AiSchedulingTurn;
use App\Models\Job;
use App\Support\Timezone;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Spatie\Activitylog\Models\Activity;

/**
 * Builds the per-job activity timeline by merging the spatie activity log with
 * the job's scheduled_messages history (queued / sent / failed / canceled),
 * ordered chronologically.
 */
class JobTimeline
{
    public static function html(Job $job): HtmlString
    {
        $events = collect();

        // Activity log entries (lead created, scheduled, payment, etc.)
        Activity::query()
            ->where('subject_type', $job->getMorphClass())
            ->where('subject_id', $job->id)
            ->with('causer')
            ->get()
            ->each(function (Activity $a) use ($events) {
                $events->push([
                    'at' => $a->created_at,
                    'icon' => '•',
                    'label' => $a->description,
                    'meta' => $a->causer?->name ? 'by '.$a->causer->name : null,
                    'tone' => 'slate',
                ]);
            });

        // Logged notes (the CRM bubbles) so the timeline reads as one story.
        $job->noteEntries()->with('author')->get()->each(function ($note) use ($events) {
            $events->push([
                'at' => $note->created_at,
                'icon' => '🗒',
                'label' => 'Note: '.$note->body.($note->wasEdited() ? ' (edited)' : ''),
                'meta' => $note->author?->name ? 'by '.$note->author->name : null,
                'tone' => 'blue',
            ]);
        });

        // Scheduled message history.
        $job->scheduledMessages()->get()->each(function ($m) use ($events) {
            [$verb, $tone, $when] = match ($m->status->value) {
                'sent' => ['sent', 'green', $m->sent_at ?? $m->updated_at],
                'failed' => ['FAILED', 'red', $m->failed_at ?? $m->updated_at],
                'canceled' => ['canceled', 'slate', $m->canceled_at ?? $m->updated_at],
                'skipped' => ['skipped', 'amber', $m->updated_at],
                'processing' => ['processing', 'blue', $m->last_attempt_at ?? $m->updated_at],
                default => ['queued', 'blue', $m->created_at],
            };

            $events->push([
                'at' => $when,
                'icon' => '✉',
                'label' => ucfirst($m->message_type->value)." SMS {$verb} ({$m->template_key->value})",
                'meta' => $m->scheduled_for ? 'for '.Timezone::toDisplay($m->scheduled_for)->format('M j, g:i A') : null,
                'tone' => $tone,
            ]);
        });

        // AI scheduling — only the actual conversation (outbound/inbound),
        // not the internal system notes ("no fast-path match", reiteration
        // counters, etc.), which belong on the AI Scheduling page's
        // transcript, not the job's general story.
        AiSchedulingTurn::query()
            ->whereIn('session_id', $job->aiSchedulingSessions()->pluck('id'))
            ->whereIn('direction', [AiSchedulingTurn::DIRECTION_OUT, AiSchedulingTurn::DIRECTION_IN])
            ->with(['technician', 'customer'])
            ->get()
            ->each(function (AiSchedulingTurn $turn) use ($events) {
                $who = match ($turn->party) {
                    AiSchedulingTurn::PARTY_TECH => $turn->technician?->name ?? 'a tech',
                    default => $turn->customer?->first_name ?? 'the customer',
                };
                $verb = $turn->direction === AiSchedulingTurn::DIRECTION_OUT ? 'AI texted' : 'AI heard back from';
                $tone = match (true) {
                    $turn->direction === AiSchedulingTurn::DIRECTION_OUT && $turn->isAwaitingApproval() => 'amber',
                    $turn->intent === 'accept' => 'green',
                    $turn->intent === 'decline' => 'red',
                    $turn->intent === 'counter' => 'amber',
                    default => 'blue',
                };

                $events->push([
                    'at' => $turn->created_at,
                    'icon' => '🤖',
                    'label' => "{$verb} {$who}: ".str($turn->body)->limit(120),
                    'meta' => $turn->direction === AiSchedulingTurn::DIRECTION_IN && $turn->intent
                        ? 'read as '.$turn->intent : null,
                    'tone' => $tone,
                ]);
            });

        return new HtmlString(self::render($events));
    }

    private static function render(Collection $events): string
    {
        if ($events->isEmpty()) {
            return '<p class="text-sm text-gray-500">No activity yet.</p>';
        }

        $rows = $events
            ->sortBy(fn ($e) => $e['at']?->timestamp ?? 0)
            ->map(function ($e) {
                $color = [
                    'green' => '#16a34a', 'red' => '#dc2626', 'amber' => '#d97706',
                    'blue' => '#2563eb', 'slate' => '#64748b',
                ][$e['tone']] ?? '#64748b';

                $time = $e['at'] ? Timezone::toDisplay($e['at'])->format('M j, Y g:i A') : '';
                $meta = $e['meta'] ? ' <span style="color:#94a3b8;">— '.e($e['meta']).'</span>' : '';

                return '<li style="display:flex;gap:.6rem;padding:.35rem 0;border-bottom:1px solid #e5e7eb33;">'
                    .'<span style="color:'.$color.';font-weight:700;">'.$e['icon'].'</span>'
                    .'<span style="flex:1;"><span style="font-weight:600;">'.e($e['label']).'</span>'.$meta
                    .'<div style="font-size:.75rem;color:#94a3b8;">'.e($time).'</div></span></li>';
            })
            ->implode('');

        return '<ul style="list-style:none;margin:0;padding:0;">'.$rows.'</ul>';
    }
}
