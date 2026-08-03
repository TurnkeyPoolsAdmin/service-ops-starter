<?php

namespace App\Filament\Resources\ScheduledMessages;

use App\Enums\MessageStatus;
use App\Filament\Resources\ScheduledMessages\Pages\ListScheduledMessages;
use App\Filament\Resources\ScheduledMessages\Tables\ScheduledMessagesTable;
use App\Models\ScheduledMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ScheduledMessageResource extends Resource
{
    protected static ?string $model = ScheduledMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Outbox / SMS';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage_jobs') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return ScheduledMessagesTable::configure($table);
    }

    /**
     * The Outbox is a pending-work view: only messages still on their way out
     * (queued/processing) or failed (so they can be retried). Sent/skipped/
     * canceled history is hidden.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('status', [
            MessageStatus::Queued->value,
            MessageStatus::Processing->value,
            MessageStatus::Failed->value,
        ])->with('job');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScheduledMessages::route('/'),
        ];
    }
}
