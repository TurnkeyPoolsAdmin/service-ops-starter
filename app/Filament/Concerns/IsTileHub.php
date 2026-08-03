<?php

namespace App\Filament\Concerns;

/**
 * Shared behavior for the Admin tile-hub landing pages (Settings, Finances):
 * a grid of tiles, one per admin/finance tool, each shown only if the
 * logged-in user can access it. Implementing classes supply their own
 * tileClasses() (the ordered list of ['class', 'label', 'icon'] tiles).
 */
trait IsTileHub
{
    /** Tiles the current user is allowed to see. */
    public static function visibleTiles(): array
    {
        return array_values(array_filter(array_map(function (array $tile): ?array {
            $class = $tile['class'];

            if (! $class::canAccess()) {
                return null;
            }

            return [
                'label' => $tile['label'],
                'icon' => $tile['icon'],
                'url' => $class::getUrl(),
            ];
        }, static::tileClasses())));
    }

    /** The hub only appears if the user can reach at least one tile. */
    public static function canAccess(): bool
    {
        return static::visibleTiles() !== [];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getTiles(): array
    {
        return static::visibleTiles();
    }
}
