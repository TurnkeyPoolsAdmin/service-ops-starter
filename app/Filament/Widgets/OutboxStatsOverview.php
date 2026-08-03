<?php

namespace App\Filament\Widgets;

use App\Enums\MessageStatus;
use App\Models\ScheduledMessage;
use App\Support\Timezone;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OutboxStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    public static function canView(): bool
    {
        return auth()->user()?->can('manage_jobs') ?? false;
    }

    protected function getStats(): array
    {
        $queued = ScheduledMessage::where('status', MessageStatus::Queued->value)->count();
        [$startUtc, $endUtc] = Timezone::localDayUtcRange(Timezone::now());
        $sentToday = ScheduledMessage::where('status', MessageStatus::Sent->value)
            ->whereBetween('sent_at', [$startUtc, $endUtc])->count();

        // No "Failed SMS" total here by design: an all-time counter just shows a
        // number nobody can act on (and quietly normalizes a non-zero one). Each
        // individual failure now fires an immediate Telegram alert the moment it
        // goes terminal (App\Jobs\SendSmsJob::alertSendFailed), and the rows
        // themselves live in Operations > Outbox / SMS for the retry.
        return [
            Stat::make('Queued SMS', $queued)->color('info'),
            Stat::make('Sent today', $sentToday)->color('success'),
        ];
    }
}
