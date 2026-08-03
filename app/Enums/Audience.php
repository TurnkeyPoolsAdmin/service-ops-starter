<?php

namespace App\Enums;

/**
 * Who a message template talks to. Derived from the template key (see
 * TemplateKey::audience) rather than stored on the row, so it can never drift
 * from the code that actually addresses the message.
 */
enum Audience: string
{
    case Customer = 'customer';
    case Tech = 'tech';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Tech => 'Technician',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Customer => 'info',
            self::Tech => 'warning',
        };
    }

    /** Options map for a Filament Select/Radio (value => label). */
    public static function options(): array
    {
        return [
            self::Customer->value => self::Customer->label(),
            self::Tech->value => self::Tech->label(),
        ];
    }
}
