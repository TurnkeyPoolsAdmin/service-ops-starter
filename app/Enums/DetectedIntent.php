<?php

namespace App\Enums;

enum DetectedIntent: string
{
    case OptOut = 'opt_out';
    case Question = 'question';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::OptOut => 'Opt-out',
            self::Question => 'Question',
            self::Other => 'Other',
        };
    }
}
