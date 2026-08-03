<?php

namespace App\Enums;

enum JobFunction: string
{
    case Sales = 'sales';
    case Technician = 'technician';
    case Office = 'office';
    case Manager = 'manager';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }
}
