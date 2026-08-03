<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\IsTileHub;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Admin > Analytics hub. A tile-hub landing page for reporting/analytics
 * tools (currently just the ROAS Dashboard). Super-admin only, by design:
 * every tile here must be gated on a permission that RoleSeeder grants to
 * NO role (like view_roas / view_issues) — access comes solely through the
 * Gate::before super_admin bypass, never a normal permission grant. The
 * explicit hasRole() check below is a belt-and-suspenders backstop so a
 * future analytics tile can never leak past that even if its permission is
 * ever mistakenly granted to another role.
 */
class Analytics extends Page
{
    use IsTileHub;

    protected string $view = 'filament.pages.tile-hub';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Analytics';

    protected static ?string $title = 'Analytics';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return (auth()->user()?->hasRole('super_admin') ?? false) && static::visibleTiles() !== [];
    }

    protected static function tileClasses(): array
    {
        return [
            ['class' => RoasDashboard::class, 'label' => 'ROAS Dashboard', 'icon' => Heroicon::OutlinedChartBar],
            ['class' => TechScoreboard::class, 'label' => 'Tech Scoreboard', 'icon' => Heroicon::OutlinedTrophy],
            ['class' => ReviewCallScoreboard::class, 'label' => 'Review Call Scoreboard', 'icon' => Heroicon::OutlinedPhone],
        ];
    }
}
