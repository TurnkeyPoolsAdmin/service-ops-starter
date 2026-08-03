<?php

namespace App\Filament\Concerns;

/**
 * Gates a Filament resource/page behind a single Spatie permission. The class
 * declares which permission via requiredPermission(); super_admins bypass all
 * checks (Gate::before), so this primarily distinguishes admin vs dispatch.
 */
trait GatedByPermission
{
    abstract protected static function requiredPermission(): string;

    public static function canAccess(): bool
    {
        return auth()->user()?->can(static::requiredPermission()) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
