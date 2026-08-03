<?php

namespace App\Enums;

enum CalibrationStatus: string
{
    /** Windshield install done — partner needs to schedule. */
    case Needed = 'needed';
    /** Partner booked a calibration visit with the customer. */
    case Scheduled = 'scheduled';
    /** Partner uploaded invoice + report and marked the visit complete. */
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Needed => 'Calibration needed',
            self::Scheduled => 'Calibration scheduled',
            self::Completed => 'Calibration completed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Needed => 'warning',
            self::Scheduled => 'info',
            self::Completed => 'success',
        };
    }

    /** Still open on the partner queue (not yet finished). */
    public function isOpen(): bool
    {
        return $this !== self::Completed;
    }
}
