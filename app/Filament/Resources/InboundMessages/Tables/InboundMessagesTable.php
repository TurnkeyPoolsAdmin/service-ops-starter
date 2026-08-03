<?php

namespace App\Filament\Resources\InboundMessages\Tables;

use App\Enums\DetectedIntent;
use App\Models\InboundMessage;
use App\Support\Timezone;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InboundMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('received_at')->label('Received')
                    ->dateTime('M j, g:i A')->timezone(Timezone::display())->sortable(),
                TextColumn::make('from_phone_e164')->label('From')->searchable(),
                TextColumn::make('customer.full_name')->label('Customer')
                    ->placeholder('— unknown —')->toggleable(),
                TextColumn::make('body')->label('Message')
                    ->wrap()->limit(80)->tooltip(fn (InboundMessage $r) => $r->body)->searchable(),
                TextColumn::make('detected_intent')->label('Intent')->badge()
                    ->formatStateUsing(fn (DetectedIntent $state) => $state->label())
                    ->color(fn (DetectedIntent $state) => $state === DetectedIntent::OptOut ? 'danger' : 'gray'),
                TextColumn::make('to_phone_e164')->label('To')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('detected_intent')
                    ->label('Intent')
                    ->options(collect(DetectedIntent::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
                Filter::make('received_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $q, array $data) {
                        return $q
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('received_at', '>=', $d))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('received_at', '<=', $d));
                    }),
            ]);
    }
}
