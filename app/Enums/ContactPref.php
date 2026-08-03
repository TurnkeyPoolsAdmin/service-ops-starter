<?php

namespace App\Enums;

enum ContactPref: string
{
    case Text = 'text';
    case Call = 'call';
    case Both = 'both';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
