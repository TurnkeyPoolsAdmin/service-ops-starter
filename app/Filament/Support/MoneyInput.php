<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;

/**
 * Shared cents<->dollars money form field: stores/dehydrates integer cents,
 * displays/hydrates as a dollar-and-cents decimal with a "$" prefix. Used by
 * every form field backed by a *_cents column (job money fields, employee
 * fixed cost, etc.) so the transform lives in exactly one place.
 */
class MoneyInput
{
    public static function make(string $field, string $label): TextInput
    {
        return TextInput::make($field)
            ->label($label)
            ->numeric()
            ->prefix('$')
            ->afterStateHydrated(fn (TextInput $component, $state) => $component->state($state !== null ? $state / 100 : null))
            ->dehydrateStateUsing(fn ($state) => $state !== null && $state !== '' ? (int) round(((float) $state) * 100) : null);
    }
}
