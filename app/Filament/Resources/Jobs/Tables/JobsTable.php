<?php

namespace App\Filament\Resources\Jobs\Tables;

use App\Enums\JobStatus;
use App\Filament\Resources\Jobs\Schemas\JobForm;
use App\Filament\Resources\Jobs\Support\JobActions;
use App\Models\Employee;
use App\Models\Entity;
use App\Support\JobSearch;
use App\Support\Money;
use App\Support\Timezone;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class JobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // The board's own search box runs the SAME rule as the topbar
            // global search (name in any order, phone in any format, address,
            // vehicle, part #). `searchUsing` replaces Filament's per-column
            // LIKE loop entirely, so the columns below no longer carry
            // ->searchable() - it would be dead configuration.
            ->searchable()
            ->searchUsing(fn (Builder $query, string $search) => JobSearch::apply($query, $search))
            ->searchPlaceholder('Name, phone, job #, address, vehicle, part #')
            ->columns([
                TextColumn::make('entity.code')->label('Entity')->badge()->sortable(),
                TextColumn::make('customer_name_snapshot')->label('Customer')
                    ->description(fn ($record) => $record->customer_phone_snapshot),
                TextColumn::make('requested_day')->label('Date requested')
                    ->state(fn ($record) => JobForm::formatRequestedDay($record->requested_day))
                    ->placeholder('—')
                    ->visible(fn ($livewire) => $livewire->activeTab === 'needs_scheduling')
                    ->visibleFrom('md'),
                TextColumn::make('requested_time')->label('Time requested')
                    ->placeholder('—')
                    ->visible(fn ($livewire) => $livewire->activeTab === 'needs_scheduling')
                    ->visibleFrom('md'),
                TextColumn::make('city')->label('City')
                    ->placeholder('—')
                    ->visible(fn ($livewire) => $livewire->activeTab === 'needs_scheduling')
                    ->visibleFrom('md'),
                // The "On hold" tab is all pending-schedule jobs, so the status
                // column says nothing there — what the reader needs is what we
                // are waiting on, and for how long.
                TextColumn::make('scheduling_hold_reason')->label('Waiting on')
                    ->state(fn ($record) => $record->scheduling_hold_reason ?: 'On hold')
                    ->description(fn ($record) => 'Since '
                        .(Timezone::toDisplay($record->scheduling_hold_at)?->format('M j') ?? '—'))
                    ->wrap()
                    ->visible(fn ($livewire) => $livewire->activeTab === 'on_hold'),
                TextColumn::make('vehicle')->label('Vehicle')
                    ->state(fn ($record) => trim(implode(' ', array_filter([
                        $record->vehicle_year, $record->vehicle_make, $record->vehicle_model,
                    ]))) ?: $record->vehicle_raw)
                    ->limit(28)->toggleable()
                    ->visible(fn ($livewire) => $livewire->activeTab !== 'needs_scheduling')
                    ->visibleFrom('md'),
                TextColumn::make('warehouse')->label('Warehouse')
                    ->state(fn ($record) => $record->warehouse?->name ?? $record->warehouse_other)
                    ->placeholder('—')->toggleable()
                    ->visible(fn ($livewire) => $livewire->activeTab !== 'needs_scheduling')
                    ->visibleFrom('md'),
                TextColumn::make('part_number')->label('Part #')
                    ->placeholder('—')->toggleable()
                    ->visible(fn ($livewire) => $livewire->activeTab !== 'needs_scheduling')
                    ->visibleFrom('md'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (JobStatus $state) => $state->label())
                    ->color(fn (JobStatus $state) => $state->color())
                    ->visible(fn ($livewire) => $livewire->activeTab !== 'needs_scheduling'),
                TextColumn::make('scheduled_at')->label('Scheduled')
                    ->dateTime('M j, g:i A')->timezone(Timezone::display())
                    ->placeholder('—')->sortable()
                    ->visible(fn ($livewire) => $livewire->activeTab !== 'needs_scheduling'),
                TextColumn::make('technician.name')->label('Tech')->placeholder('—')->toggleable()
                    ->visible(fn ($livewire) => $livewire->activeTab !== 'needs_scheduling'),
                TextColumn::make('quote_amount_cents')->label('Quote')
                    ->formatStateUsing(fn ($state) => Money::format($state))->alignEnd()
                    ->visible(fn ($livewire) => $livewire->activeTab !== 'needs_scheduling')
                    ->visibleFrom('md'),
                TextColumn::make('insurance_status')->label('Insurance')
                    ->state(fn ($record) => ! $record->is_insurance
                        ? null
                        : ($record->isInsurancePaid() ? 'Paid' : 'Awaiting'))
                    ->badge()
                    ->color(fn ($record) => $record->isInsurancePaid() ? 'success' : 'warning')
                    ->placeholder('—')->toggleable()
                    ->visible(fn ($livewire) => $livewire->activeTab !== 'needs_scheduling')
                    ->visibleFrom('lg'),
                TextColumn::make('needs_calibration')->label('Calibration')
                    ->state(fn ($record) => $record->needsCalibration() ? 'Needs calibration' : null)
                    ->badge()->color('warning')
                    ->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn ($livewire) => $livewire->activeTab !== 'needs_scheduling')
                    ->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(JobStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
                SelectFilter::make('entity_id')->label('Entity')
                    ->options(fn () => Entity::pluck('code', 'id')),
                SelectFilter::make('technician_id')->label('Tech')
                    ->options(fn () => Employee::withJobFunction('technician')->orderBy('name')->pluck('name', 'id')),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(JobActions::all())->label('Actions')->button(),
            ]);
    }
}
