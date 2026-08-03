<?php

namespace App\Filament\Resources\MessageTemplates;

use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\MessageTemplates\Pages\EditMessageTemplate;
use App\Filament\Resources\MessageTemplates\Pages\ListMessageTemplates;
use App\Filament\Resources\MessageTemplates\Schemas\MessageTemplateForm;
use App\Filament\Resources\MessageTemplates\Tables\MessageTemplatesTable;
use App\Models\MessageTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MessageTemplateResource extends Resource
{
    use GatedByPermission;

    protected static function requiredPermission(): string
    {
        return 'manage_templates';
    }

    // Reached via the Admin > Settings hub, not its own sidebar item.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static ?string $model = MessageTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $recordTitleAttribute = 'name';

    // Templates are fixed by the app's lifecycle (keys defined in TemplateKey);
    // operators edit copy but never create or delete rows from the UI.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return MessageTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MessageTemplatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMessageTemplates::route('/'),
            'edit' => EditMessageTemplate::route('/{record}/edit'),
        ];
    }
}
