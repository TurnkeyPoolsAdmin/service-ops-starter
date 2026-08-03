<?php

namespace App\Enums;

enum PartsSupplier: string
{
    case Mygrant = 'mygrant';
    case Igc = 'igc';

    public function label(): string
    {
        return match ($this) {
            self::Mygrant => 'Mygrant Glass',
            self::Igc => 'Import Glass Corp (IGC)',
        };
    }

    /** Map for Filament select options. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }
}
