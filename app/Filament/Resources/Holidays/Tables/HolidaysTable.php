<?php

namespace App\Filament\Resources\Holidays\Tables;

use App\Models\Holiday;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HolidaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('type', 'asc')
            ->columns([
                TextColumn::make('label')->placeholder('—')->searchable()->weight('bold'),
                TextColumn::make('type')->label('Type')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        Holiday::TYPE_ANNUAL => 'Annual',
                        Holiday::TYPE_RELATIVE => 'Relative',
                        default => 'Specific date',
                    })
                    ->color(fn (string $state) => match ($state) {
                        Holiday::TYPE_ANNUAL => 'success',
                        Holiday::TYPE_RELATIVE => 'info',
                        default => 'warning',
                    }),
                TextColumn::make('rule')->label('When')
                    ->state(fn (Holiday $record) => $record->describe()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
