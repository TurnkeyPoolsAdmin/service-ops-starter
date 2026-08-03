<?php

namespace App\Filament\Resources\InboundMessages;

use App\Filament\Resources\InboundMessages\Pages\ListInboundMessages;
use App\Filament\Resources\InboundMessages\Tables\InboundMessagesTable;
use App\Models\InboundMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only viewer for received texts. Every provider's inbound webhook
 * (Quo, RingCentral) writes to the same inbound_messages table, so this shows
 * the full incoming stream in one place — handy for RingCentral, which (unlike
 * Quo) has no external inbox we deep-link to.
 */
class InboundMessageResource extends Resource
{
    protected static ?string $model = InboundMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Incoming Texts';

    protected static ?int $navigationSort = 3;

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
        return parent::getEloquentQuery()->with('customer');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return InboundMessagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInboundMessages::route('/'),
        ];
    }
}
