<?php

namespace App\Filament\Resources\MessageTemplates\Tables;

use App\Enums\Audience;
use App\Enums\Language;
use App\Enums\TemplateCategory;
use App\Models\MessageTemplate;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MessageTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // The human name leads — the key is developer shorthand, still
                // searchable but hidden until someone asks for it.
                TextColumn::make('name')->label('Template')->searchable()->wrap(),
                TextColumn::make('category')->label('Stage')->badge()
                    ->state(fn (MessageTemplate $record) => $record->category()?->label())
                    ->color(fn (MessageTemplate $record) => $record->category()?->color())
                    ->placeholder('—'),
                TextColumn::make('language')->badge()->sortable()
                    ->formatStateUsing(fn (Language $state) => $state->code())
                    ->color(fn (Language $state) => $state->color()),
                TextColumn::make('audience')->label('Sent to')->badge()
                    ->state(fn (MessageTemplate $record) => $record->audience()?->label())
                    ->color(fn (MessageTemplate $record) => $record->audience()?->color())
                    ->placeholder('—'),
                TextColumn::make('body')->label('Message')->limit(80)->wrap()->searchable(),
                TextColumn::make('key')->badge()->color('gray')->searchable()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('active')->boolean(),
            ])
            // Keeps both translations of a template next to each other.
            ->defaultSort('key')
            ->filters([
                // Category is derived from the key (TemplateKey::category), so it
                // filters as a key list rather than as a column. The tabs on the
                // list page are the primary way in; this select is here so it can
                // be combined with language / audience.
                SelectFilter::make('category')
                    ->label('Stage')
                    ->options(TemplateCategory::options())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->inCategory(TemplateCategory::from($data['value']))
                        : $query),
                SelectFilter::make('language')
                    ->label('Language')
                    ->options(Language::options()),
                // Audience is derived from the key (TemplateKey::audience), so it
                // filters as a key list rather than as a column.
                SelectFilter::make('audience')
                    ->label('Sent to')
                    ->options(Audience::options())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->forAudience(Audience::from($data['value']))
                        : $query),
                TernaryFilter::make('active'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
