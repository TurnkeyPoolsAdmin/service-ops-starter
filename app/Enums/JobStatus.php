<?php

namespace App\Enums;

enum JobStatus: string
{
    case PendingSchedule = 'pending_schedule';
    case Scheduled = 'scheduled';
    case WorkCompleted = 'work_completed';
    case Completed = 'completed';
    case Canceled = 'canceled';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::PendingSchedule => 'Pending schedule',
            self::Scheduled => 'Scheduled',
            self::WorkCompleted => 'Work completed',
            self::Completed => 'Completed',
            self::Canceled => 'Canceled',
            self::Lost => 'Lost',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingSchedule => 'warning',
            self::Scheduled => 'info',
            self::WorkCompleted => 'primary',
            self::Completed => 'success',
            self::Canceled => 'gray',
            self::Lost => 'danger',
        };
    }

    /** Statuses considered "open" / still in the operational pipeline. */
    public static function openCases(): array
    {
        return [self::PendingSchedule, self::Scheduled, self::WorkCompleted];
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Canceled, self::Lost], true);
    }
}
