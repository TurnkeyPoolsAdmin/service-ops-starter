<?php

namespace App\Filament\Resources\Warehouses\Schemas;

use App\Enums\PartsSupplier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class WarehouseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                Toggle::make('active')->default(true),
                Select::make('supplier')
                    ->label('Parts supplier')
                    ->options(PartsSupplier::options())
                    ->placeholder('None (dealer / other)')
                    ->live(),
                TextInput::make('supplier_code')
                    ->label('Mygrant warehouse code')
                    ->helperText('e.g. B014 — used to build the Mygrant search link')
                    ->visible(fn ($get) => $get('supplier') === PartsSupplier::Mygrant->value)
                    ->required(fn ($get) => $get('supplier') === PartsSupplier::Mygrant->value),
            ]);
    }
}
