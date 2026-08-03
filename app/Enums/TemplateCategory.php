<?php

namespace App\Enums;

/**
 * How the Message Templates dashboard is organized: the stage of the job a
 * template belongs to. Derived from the key (see TemplateKey::category), never
 * stored, so it can't drift from the code that sends the message.
 *
 * Deliberately SEPARATE from MessageType, which drives behavior (opt-out
 * language, outbox typing) and groups things by how they are treated rather
 * than by where they sit in the job. This one exists only to make the ~50 rows
 * findable — e.g. the scheduling asks are MessageType::Welcome (they continue
 * the intake thread) but read as Scheduling to an operator hunting for them.
 */
enum TemplateCategory: string
{
    case Welcome = 'welcome';
    case Scheduling = 'scheduling';
    case Reminders = 'reminders';
    case Completion = 'completion';
    case Payment = 'payment';
    case Reviews = 'reviews';
    case Warranty = 'warranty';
    case Calibration = 'calibration';
    case TechCrew = 'tech_crew';

    public function label(): string
    {
        return match ($this) {
            self::Welcome => 'Welcome',
            self::Scheduling => 'Scheduling',
            self::Reminders => 'Reminders',
            self::Completion => 'Job completion',
            self::Payment => 'Payment',
            self::Reviews => 'Reviews',
            self::Warranty => 'Warranty',
            self::Calibration => 'Calibration',
            self::TechCrew => 'Tech crew',
        };
    }

    /** One line on the tab, so an operator knows which bucket to open. */
    public function description(): string
    {
        return match ($this) {
            self::Welcome => 'First contact — intro texts when a lead comes in.',
            self::Scheduling => 'Asking for and confirming the appointment time, plus reschedules and cancellations.',
            self::Reminders => 'Night-before and morning-of nudges for the appointment.',
            self::Completion => 'Sent when the work is marked complete, one per payment method.',
            self::Payment => 'Card payment links and receipts.',
            self::Reviews => 'The post-payment review drip.',
            self::Warranty => 'Return-visit texts when a customer reports an issue.',
            self::Calibration => 'ADAS calibration hand-off and completion.',
            self::TechCrew => 'Texts addressed to a technician, not a customer.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Welcome => 'info',
            self::Scheduling => 'primary',
            self::Reminders => 'warning',
            self::Completion => 'success',
            self::Payment => 'success',
            self::Reviews => 'info',
            self::Warranty => 'danger',
            self::Calibration => 'gray',
            self::TechCrew => 'gray',
        };
    }

    /** Options map for a Filament Select (value => label). */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
