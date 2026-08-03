<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Sent = 'sent';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Skipped = 'skipped';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'info',
            self::Processing => 'warning',
            self::Sent => 'success',
            self::Failed => 'danger',
            self::Canceled => 'gray',
            self::Skipped => 'gray',
        };
    }

    /** Statuses that are still pending an attempt (cancelable / unsent). */
    public static function unsentCases(): array
    {
        return [self::Queued, self::Processing];
    }
}
