<?php

namespace App\Enums;

enum ParseStatus: string
{
    case Clean = 'clean';
    case Warnings = 'warnings';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Clean => 'Clean',
            self::Warnings => 'Warnings',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Clean => 'success',
            self::Warnings => 'warning',
            self::Failed => 'danger',
        };
    }
}
