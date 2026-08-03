<?php

namespace App\Filament\Resources\WarrantyClaims;

use App\Filament\Resources\WarrantyClaims\Pages\ListWarrantyClaims;
use App\Filament\Resources\WarrantyClaims\Tables\WarrantyClaimsTable;
use App\Models\WarrantyClaim;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The warranty dashboard: completed jobs where the customer reported an issue.
 * Add notes, schedule the unpaid tech return visit (which texts the customer
 * one generic heads-up and puts the visit on the calendar), and resolve when
 * fixed. Claims are opened from a job's "Warranty / fix needed" action — never
 * here. The nav badge counts claims still needing a visit scheduled.
 */
class WarrantyClaimResource extends Resource
{
    protected static ?string $model = WarrantyClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Warranty';

    protected static ?string $modelLabel = 'warranty claim';

    protected static ?string $pluralModelLabel = 'warranty claims';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage_jobs') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['job', 'customer', 'entity', 'technician']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = WarrantyClaim::needsScheduling()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return WarrantyClaimsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWarrantyClaims::route('/'),
        ];
    }
}
