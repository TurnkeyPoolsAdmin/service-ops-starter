<?php

namespace App\Filament\Resources\Jobs;

use App\Filament\Resources\Jobs\Pages\EditJob;
use App\Filament\Resources\Jobs\Pages\ListJobs;
use App\Filament\Resources\Jobs\Pages\ViewJob;
use App\Filament\Resources\Jobs\Schemas\JobForm;
use App\Filament\Resources\Jobs\Schemas\JobInfolist;
use App\Filament\Resources\Jobs\Tables\JobsTable;
use App\Models\Job;
use App\Support\JobSearch;
use App\Support\Money;
use App\Support\PhoneNumber;
use App\Support\Timezone;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class JobResource extends Resource
{
    protected static ?string $model = Job::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $recordTitleAttribute = 'job_number';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage_jobs') ?? false;
    }

    // Jobs are created through the Intake page, not the generic create form.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['entity', 'technician', 'customer', 'warehouse']);
    }

    /**
     * The topbar search box, on every page of the panel.
     *
     * Filament's default is the record-title attribute ALONE - here that is
     * `job_number`, so a name or a phone number found nothing at all. The list
     * below is what the box advertises; the real matching is
     * JobSearch::apply() (see applyGlobalSearchAttributeConstraints), which
     * also reaches the `customers` row and normalizes phones.
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return array_merge(JobSearch::JOB_COLUMNS, [
            'customer_phone_snapshot',
            'customer.first_name',
            'customer.last_name',
            'customer.phone_e164',
            'customer.email',
        ]);
    }

    /**
     * Replaces Filament's own word/attribute LIKE loop wholesale, so the
     * topbar box and the jobs board search behave identically. Filament splits
     * on whitespace only; JobSearch also splits on commas and matches phone
     * digits to phone digits.
     */
    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        JobSearch::apply($query, $search);
    }

    /** Newest first - a search that hits a repeat customer wants this job, not their 2026 one. */
    public static function modifyGlobalSearchQuery(Builder $query, string $search): void
    {
        $query->orderByDesc('created_at');
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Job $record */
        return trim(($record->customer_name_snapshot ?: 'No name').' - '.$record->job_number);
    }

    /**
     * What the operator needs to tell two jobs for the same customer apart.
     *
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Job $record */
        $vehicle = trim(implode(' ', array_filter([
            $record->vehicle_year, $record->vehicle_make, $record->vehicle_model,
        ]))) ?: $record->vehicle_raw;

        return array_filter([
            'Phone' => PhoneNumber::toDisplay($record->customer_phone_snapshot),
            'Status' => $record->status->label(),
            'Scheduled' => $record->scheduled_at
                ?->setTimezone(Timezone::display())->format('M j, g:i A'),
            'Vehicle' => $vehicle,
            'Quote' => $record->quote_amount_cents ? Money::format($record->quote_amount_cents) : null,
        ], fn (?string $value): bool => filled($value));
    }

    public static function form(Schema $schema): Schema
    {
        return JobForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return JobInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JobsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJobs::route('/'),
            'view' => ViewJob::route('/{record}'),
            'edit' => EditJob::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
