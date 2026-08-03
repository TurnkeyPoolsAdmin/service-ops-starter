<?php

namespace App\Filament\Resources\Holidays\Schemas;

use App\Models\Holiday;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class HolidayForm
{
    private const MONTHS = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
        7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    private const WEEKDAYS = [
        0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
        4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
    ];

    private const WEEKS = [
        1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th', -1 => 'Last',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('Recurrence')
                    ->options([
                        Holiday::TYPE_ANNUAL => 'Annual — same date every year (e.g. Jan 1)',
                        Holiday::TYPE_RELATIVE => 'Relative — Nth weekday of a month (e.g. 3rd Thursday of November)',
                        Holiday::TYPE_FIXED => 'Specific date — one-off; must be re-added each year',
                    ])
                    ->default(Holiday::TYPE_ANNUAL)
                    ->required()
                    ->native(false)
                    ->live(),

                TextInput::make('label')
                    ->label('Label (optional)')
                    ->placeholder('e.g. Independence Day')
                    ->maxLength(255),

                // Specific (fixed) one-off date.
                DatePicker::make('date')
                    ->label('Holiday date (Pacific)')
                    ->native(false)
                    ->visible(fn (Get $get) => $get('type') === Holiday::TYPE_FIXED)
                    ->required(fn (Get $get) => $get('type') === Holiday::TYPE_FIXED)
                    ->helperText('A single calendar date. Add a fresh row each year for floating holidays.'),

                // Annual: month + day.
                Select::make('month')
                    ->label('Month')
                    ->options(self::MONTHS)
                    ->native(false)
                    ->visible(fn (Get $get) => in_array($get('type'), [Holiday::TYPE_ANNUAL, Holiday::TYPE_RELATIVE], true))
                    ->required(fn (Get $get) => in_array($get('type'), [Holiday::TYPE_ANNUAL, Holiday::TYPE_RELATIVE], true)),

                TextInput::make('day')
                    ->label('Day of month')
                    ->numeric()->minValue(1)->maxValue(31)
                    ->visible(fn (Get $get) => $get('type') === Holiday::TYPE_ANNUAL)
                    ->required(fn (Get $get) => $get('type') === Holiday::TYPE_ANNUAL),

                // Relative: which week + which weekday (month shared with annual above).
                Select::make('week')
                    ->label('Which one')
                    ->options(self::WEEKS)
                    ->native(false)
                    ->visible(fn (Get $get) => $get('type') === Holiday::TYPE_RELATIVE)
                    ->required(fn (Get $get) => $get('type') === Holiday::TYPE_RELATIVE),

                Select::make('weekday')
                    ->label('Weekday')
                    ->options(self::WEEKDAYS)
                    ->native(false)
                    ->visible(fn (Get $get) => $get('type') === Holiday::TYPE_RELATIVE)
                    ->required(fn (Get $get) => $get('type') === Holiday::TYPE_RELATIVE),
            ]);
    }
}
