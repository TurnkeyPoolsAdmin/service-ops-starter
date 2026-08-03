<?php

namespace App\Filament\Resources\ReviewRequests;

use App\Filament\Resources\ReviewRequests\Pages\ListReviewRequests;
use App\Filament\Resources\ReviewRequests\Tables\ReviewRequestsTable;
use App\Models\ReviewRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The review dashboard: every customer in the call-first review flow, what
 * step they're on, and the manual moves (log the first/second call, mark the
 * review left, pause/cancel). Rows are created when work is marked complete
 * (or, for a calibration job, when calibration is confirmed) — never here.
 * The nav badge counts rows waiting on a first OR second call.
 */
class ReviewRequestResource extends Resource
{
    protected static ?string $model = ReviewRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Reviews';

    protected static ?string $modelLabel = 'review drip';

    protected static ?string $pluralModelLabel = 'review drips';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage_jobs') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Thumbs-up drips and awaiting_call rows (no sentiment yet — the whole
     * point is calling before it's known) belong on the dashboard; skipped/
     * down rows are noise.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(fn (Builder $q) => $q->where('sentiment', 'up')
                ->orWhere('status', ReviewRequest::STATUS_AWAITING_CALL))
            ->with(['job', 'customer', 'entity']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ReviewRequest::awaitingFirstCall()->count() + ReviewRequest::callNeeded()->count();

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
        return ReviewRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviewRequests::route('/'),
        ];
    }
}
