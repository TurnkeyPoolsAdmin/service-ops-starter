<?php

namespace App\Filament\Resources\WarrantyClaims\Tables;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\Employee;
use App\Models\WarrantyClaim;
use App\Services\Warranty\WarrantyService;
use App\Support\Money;
use App\Support\Timezone;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WarrantyClaimsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('job.job_number')
                    ->label('Job')
                    ->url(fn (WarrantyClaim $record) => $record->job
                        ? JobResource::getUrl('view', ['record' => $record->job]) : null)
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('job.customer_name_snapshot')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('customer.phone_e164')
                    ->label('Phone')
                    ->url(fn (WarrantyClaim $record) => $record->customer?->quo_conversation_url, shouldOpenInNewTab: true)
                    ->color(fn (WarrantyClaim $record) => filled($record->customer?->quo_conversation_url) ? 'primary' : null)
                    ->searchable(),
                TextColumn::make('entity.code')
                    ->label('Loc')
                    ->badge(),
                TextColumn::make('issue')
                    ->label('Issue')
                    ->limit(60)
                    ->tooltip(fn (WarrantyClaim $record) => $record->issue)
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (WarrantyClaim $record) => $record->statusLabel())
                    ->badge()
                    ->color(fn (WarrantyClaim $record) => $record->statusColor()),
                TextColumn::make('scheduled_at')
                    ->label('Return visit')
                    ->state(fn (WarrantyClaim $record) => $record->visitPhrase() ?: '—'),
                TextColumn::make('technician.name')
                    ->label('Tech')
                    ->placeholder('—'),
                TextColumn::make('tech_pay_cents')
                    ->label('Extra pay')
                    ->state(fn (WarrantyClaim $record) => $record->hasTechPay()
                        ? Money::format((int) $record->tech_pay_cents).($record->tech_paid_at ? ' (paid)' : '')
                        : '—')
                    ->color(fn (WarrantyClaim $record) => $record->isWarrantyPayOwed() ? 'warning' : null),
            ])
            ->recordActions([
                self::scheduleAction(),
                self::addNoteAction(),
                self::resolveAction(),
                self::cancelAction(),
            ]);
    }

    private static function warranty(): WarrantyService
    {
        return app(WarrantyService::class);
    }

    /**
     * Book (or move) the unpaid return visit — same date/time/phrasing fields
     * as scheduling a job. Sends the one generic "we'll be there on …" text
     * (again only if the time actually changed) and puts it on the calendar.
     */
    private static function scheduleAction(): Action
    {
        return Action::make('scheduleVisit')
            ->label(fn (WarrantyClaim $record) => $record->status === WarrantyClaim::STATUS_SCHEDULED
                ? 'Reschedule visit' : 'Schedule visit')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color(fn (WarrantyClaim $record) => $record->status === WarrantyClaim::STATUS_OPEN ? 'warning' : 'gray')
            ->visible(fn (WarrantyClaim $record) => $record->isLive())
            ->fillForm(function (WarrantyClaim $record) {
                $local = Timezone::toDisplay($record->scheduled_at);

                return [
                    'scheduled_date' => $local?->format('Y-m-d'),
                    'scheduled_time' => $local?->format('H:i'),
                    'time_phrasing' => $record->scheduled_exact_time
                        ? 'exact'
                        : ($record->scheduled_window_hours ? 'custom_window' : 'window'),
                    'window_hours' => $record->scheduled_window_hours,
                    'technician_id' => $record->technician_id,
                    'tech_needs_pay' => $record->hasTechPay(),
                    'tech_pay' => $record->hasTechPay() ? number_format($record->tech_pay_cents / 100, 2, '.', '') : null,
                ];
            })
            ->schema([
                DatePicker::make('scheduled_date')
                    ->label('Visit date (Pacific)')
                    ->native(false)
                    ->required(),
                TimePicker::make('scheduled_time')
                    ->label('Visit time (Pacific)')
                    ->seconds(false)
                    ->required(),
                Radio::make('time_phrasing')
                    ->label('Customer text says')
                    ->options([
                        'window' => '2-hour window',
                        'custom_window' => 'Different time window',
                        'exact' => 'Exact time',
                    ])
                    ->default('window')
                    ->required()
                    ->live(),
                Select::make('window_hours')
                    ->label('Window length')
                    ->options([1 => '1 hour (e.g. 8am-9am)', 3 => '3 hours (e.g. 8am-11am)', 4 => '4 hours (e.g. 8am-12pm)'])
                    ->visible(fn ($get) => $get('time_phrasing') === 'custom_window')
                    ->required(fn ($get) => $get('time_phrasing') === 'custom_window'),
                Select::make('technician_id')
                    ->label('Technician')
                    ->options(Employee::active()->withJobFunction('technician')->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->helperText('Sending a different tech than the original installer raises an Issue.'),
                Toggle::make('tech_needs_pay')
                    ->label('Does the tech require additional pay?')
                    ->helperText('Return visits are normally unpaid. Turn this on only if the tech is being paid for this visit.')
                    ->default(false)
                    ->live(),
                TextInput::make('tech_pay')
                    ->label('Additional pay ($)')
                    ->numeric()
                    ->prefix('$')
                    ->minValue(0.01)
                    ->step('0.01')
                    ->visible(fn ($get) => (bool) $get('tech_needs_pay'))
                    ->required(fn ($get) => (bool) $get('tech_needs_pay'))
                    ->helperText('Shows on the End-of-Day summary and is reconciled against the bank like a normal payout.'),
            ])
            ->action(function (WarrantyClaim $record, array $data) {
                $date = Carbon::parse($data['scheduled_date'], Timezone::display())->format('Y-m-d');
                $time = Carbon::parse($data['scheduled_time'])->format('H:i');
                $scheduledLocal = Carbon::parse("{$date} {$time}", Timezone::display());

                $techPayCents = ! empty($data['tech_needs_pay']) && filled($data['tech_pay'] ?? null)
                    ? (int) round(((float) $data['tech_pay']) * 100)
                    : null;

                $message = self::warranty()->schedule(
                    $record,
                    $scheduledLocal,
                    auth()->user(),
                    technicianId: $data['technician_id'] ?? null,
                    exactTime: ($data['time_phrasing'] ?? 'window') === 'exact',
                    windowHours: ($data['time_phrasing'] ?? 'window') === 'custom_window'
                        ? (int) $data['window_hours'] : null,
                    techPayCents: $techPayCents,
                );

                Notification::make()->title('Warranty visit scheduled')
                    ->body($message !== null
                        ? 'The customer is being texted the visit time.'
                        : 'No text sent — the customer may be opted out or paused, or this exact time was already announced.')
                    ->success()->send();
            });
    }

    /** Running internal notes on the claim (parts ordered, findings, etc.). */
    private static function addNoteAction(): Action
    {
        return Action::make('editNotes')
            ->label('Notes')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->fillForm(fn (WarrantyClaim $record) => [
                'issue' => $record->issue,
                'notes' => $record->notes,
            ])
            ->schema([
                Textarea::make('issue')->label('What the customer reported')->rows(3)->required(),
                Textarea::make('notes')->label('Internal notes')->rows(4),
            ])
            ->action(function (WarrantyClaim $record, array $data) {
                $record->update(['issue' => $data['issue'], 'notes' => $data['notes']]);
                Notification::make()->title('Notes saved')->success()->send();
            });
    }

    private static function resolveAction(): Action
    {
        return Action::make('resolve')
            ->label('Mark complete')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (WarrantyClaim $record) => $record->isLive())
            ->schema([
                Radio::make('sentiment')
                    ->label('How did it go?')
                    ->options(['up' => 'Went well', 'down' => 'Had a problem'])
                    ->default('up')
                    ->required()
                    ->inline()
                    ->inlineLabel(false),
                Textarea::make('resolution_notes')->label('How was it resolved? (optional)')->rows(2),
            ])
            ->modalHeading('Mark this warranty visit complete?')
            ->modalDescription('Use once the issue is taken care of. The customer gets an "all set" text (no review request), and the claim moves to the Resolved tab. If the visit had extra pay, it becomes owed on the End-of-Day summary.')
            ->modalSubmitActionLabel('Mark complete')
            ->action(function (WarrantyClaim $record, array $data) {
                self::warranty()->resolve(
                    $record,
                    $data['resolution_notes'] ?? null,
                    auth()->user(),
                    sentiment: $data['sentiment'] ?? null,
                );
                Notification::make()->title('Warranty visit completed')->success()->send();
            });
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancelClaim')
            ->label('Cancel')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (WarrantyClaim $record) => $record->isLive())
            ->schema([
                TextInput::make('reason')->label('Reason (optional)'),
            ])
            ->requiresConfirmation()
            ->modalHeading('Cancel this warranty claim?')
            ->modalDescription('Use when it was opened by mistake or the customer withdrew. No further action happens.')
            ->action(function (WarrantyClaim $record, array $data) {
                self::warranty()->cancel($record, $data['reason'] ?: null, auth()->user());
                Notification::make()->title('Warranty claim canceled')->success()->send();
            });
    }
}
