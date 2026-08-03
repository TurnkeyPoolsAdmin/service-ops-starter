<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Enums\JobFunction;
use App\Enums\Language;
use App\Filament\Support\MoneyInput;
use App\Models\Employee;
use App\Models\Entity;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        // Email is the login identifier — only relevant for users who log in.
                        TextInput::make('email')->email()->maxLength(255)->unique(ignoreRecord: true)
                            ->visible(fn (Get $get) => (bool) $get('can_login')),
                        // Phone is only needed to reach technicians.
                        TextInput::make('phone')->tel()->maxLength(255)
                            ->visible(fn (Get $get) => in_array(JobFunction::Technician->value, $get('job_functions') ?? [], true)),
                        // How this tech's Zelle payout appears on Chase, so the bank
                        // ledger can auto-suggest their End-of-Day payouts.
                        TextInput::make('zelle_payout_name')
                            ->label('Zelle payout name')
                            ->maxLength(255)
                            ->helperText('Exactly as Chase shows it — e.g. "Zelle payment to Jose Aguirre" → enter "Jose Aguirre". Used to auto-match End-of-Day payouts on the bank ledger.')
                            ->visible(fn (Get $get) => in_array(JobFunction::Technician->value, $get('job_functions') ?? [], true)),
                        // Which language this tech's automated texts go out in.
                        Select::make('preferred_language')
                            ->label('Preferred language')
                            ->options(Language::options())
                            ->default(Language::Spanish->value)
                            ->native(false)
                            ->selectablePlaceholder(false)
                            ->helperText('Language for texts sent to this tech (e.g. the "job canceled" notice).')
                            ->visible(fn (Get $get) => in_array(JobFunction::Technician->value, $get('job_functions') ?? [], true)),
                        // Tint for this tech's blocks on the scheduling calendar.
                        ColorPicker::make('calendar_color')
                            ->label('Calendar color')
                            ->helperText('Color used for this tech\'s appointments on the scheduling calendar.')
                            ->visible(fn (Get $get) => in_array(JobFunction::Technician->value, $get('job_functions') ?? [], true)),
                        Toggle::make('active')->default(true),
                        CheckboxList::make('job_functions')
                            ->options(JobFunction::options())
                            ->columns(2)
                            ->live()
                            ->helperText('Drives the salesperson / technician dropdowns at intake and scheduling.')
                            ->columnSpanFull(),
                        Select::make('entity_id')
                            ->label('Entity (location)')
                            ->options(fn () => Entity::orderBy('code')->pluck('name', 'id'))
                            ->searchable()
                            ->helperText('Which location this technician works for.')
                            ->visible(fn (Get $get) => in_array(JobFunction::Technician->value, $get('job_functions') ?? [], true)),
                        // Ranking is per tech pool, not per entity: SFV and AGLA share
                        // one crew, so they share one 1-2-3; Del Mar ranks its own.
                        TextInput::make('priority')
                            ->label('Priority ranking')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(99)
                            ->placeholder('Unranked')
                            ->helperText('Who to reach for first at this location — 1 = first choice, 2 = next, and so on. Leave blank to leave this tech unranked (they sort last). SFV / AGLA share one ranking; Del Mar has its own.')
                            ->visible(fn (Get $get) => in_array(JobFunction::Technician->value, $get('job_functions') ?? [], true)),
                        MoneyInput::make('fixed_cost_cents', 'Fixed cost per job ($)')
                            ->helperText('Flat subcontractor rate this tech charges per job. Auto-fills the job\'s tech pay when assigned.')
                            ->visible(fn (Get $get) => in_array(JobFunction::Technician->value, $get('job_functions') ?? [], true)),
                        Toggle::make('eod_summary_texts_enabled')
                            ->label('End-of-day summary texts')
                            ->helperText('Text this tech a nightly summary of the day\'s completed jobs, labor pay, cash collected, and net pay. Sends only on days they completed work.')
                            ->visible(fn (Get $get) => in_array(JobFunction::Technician->value, $get('job_functions') ?? [], true)),
                        Toggle::make('ai_scheduling_enabled')
                            ->label('AI scheduling texts')
                            ->helperText('Let "Schedule w/ AI" text this tech proposed job times and negotiate over SMS. Off by default — a tech is never contacted by the AI until this is turned on.')
                            ->visible(fn (Get $get) => in_array(JobFunction::Technician->value, $get('job_functions') ?? [], true)),
                    ]),
                Section::make('Login & role')
                    ->description('Only employees who log in need a password and a role.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('can_login')->live()->default(false),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->dehydrateStateUsing(fn (?string $state) => filled($state) ? Hash::make($state) : null)
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->required(fn (string $operation, Get $get) => $operation === 'create' && $get('can_login'))
                            ->visible(fn (Get $get) => (bool) $get('can_login')),
                        Select::make('roles')
                            // Only super_admins may grant the elevated roles; everyone
                            // else can assign dispatch only.
                            ->relationship('roles', 'name', function (Builder $query) {
                                if (! self::isSuperAdmin()) {
                                    $query->where('name', 'dispatch');
                                }
                            })
                            ->multiple()
                            ->preload()
                            ->visible(fn (Get $get) => (bool) $get('can_login'))
                            // Admins can't alter the roles of an already-elevated account.
                            ->disabled(fn (?Employee $record) => self::rolesLocked($record))
                            ->dehydrated(fn (?Employee $record) => ! self::rolesLocked($record))
                            ->helperText(self::isSuperAdmin()
                                ? 'super_admin = full access. admin = ops + entities, employees, delete jobs (no templates/warehouses/APIs/timing/ROAS). dispatch = ops only. finance = bank ledger only. calibration = Calibration partner portal only.'
                                : 'Only a super_admin can grant the admin, super_admin, finance or calibration roles.'),
                    ]),
            ]);
    }

    private static function isSuperAdmin(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    /** Non-super-admins may not modify roles on an account that already holds an elevated role. */
    private static function rolesLocked(?Employee $record): bool
    {
        return ! self::isSuperAdmin() && ($record?->hasAnyRole(['super_admin', 'admin', 'finance', 'calibration']) ?? false);
    }
}
