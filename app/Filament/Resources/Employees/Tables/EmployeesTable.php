<?php

namespace App\Filament\Resources\Employees\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('job_functions')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ucfirst((string) $state)),
                TextColumn::make('entity.name')->label('Entity')->placeholder('—')->toggleable(),
                TextColumn::make('priority')
                    ->label('Priority')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state) => '#'.$state)
                    ->placeholder('—')
                    ->sortable(query: fn ($query, string $direction) => $query
                        ->orderByRaw('priority IS NULL')
                        ->orderBy('priority', $direction))
                    ->toggleable(),
                TextColumn::make('roles.name')->badge()->label('Roles'),
                IconColumn::make('can_login')->boolean()->label('Login'),
                IconColumn::make('active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('active'),
                TernaryFilter::make('can_login')->label('Can log in'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
        // No bulk delete: deletions go through EditEmployee, which protects the
        // super_admin account from removal.
    }
}
