<?php

namespace App\Filament\Pages;

use App\Enums\CalibrationStatus;
use App\Models\Job;
use App\Services\Calibration\CalibrationService;
use App\Support\Timezone;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/**
 * ADAS calibration partner pipeline (the calibration partner + ops).
 *
 * Jobs land here after the windshield install is marked complete when the job
 * needsCalibration(). Partner (role calibration) only has this section —
 * Schedule calibration & Mark as completed (invoice + report PDFs). Ops
 * (manage_jobs) also confirm thumbs after the partner finishes, which sends
 * the final SMS and starts the review drip.
 */
class CalibrationJobs extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.calibration-jobs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Calibration';

    protected static ?string $title = 'Calibration';

    protected static ?int $navigationSort = 3;

    /** open | needed | scheduled | awaiting | completed | all */
    #[Url]
    public string $view_tab = 'open';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->can('manage_calibration') || $user?->can('manage_jobs')) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Job::query()
            ->whereIn('calibration_status', [
                CalibrationStatus::Needed->value,
                CalibrationStatus::Scheduled->value,
            ])
            ->count()
            + Job::query()
                ->where('calibration_status', CalibrationStatus::Completed->value)
                ->whereNull('calibration_confirmed_at')
                ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function getSubheading(): ?string
    {
        return 'Windshield install done — calibration partner schedules the separate ADAS visit, '
            .'uploads invoice + report, then ops confirms thumbs and the review texts start.';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->tabAction('open', 'Open', Job::query()->whereIn('calibration_status', [
                CalibrationStatus::Needed->value,
                CalibrationStatus::Scheduled->value,
            ])->count()),
            $this->tabAction('needed', 'Needed'),
            $this->tabAction('scheduled', 'Scheduled'),
            $this->tabAction('awaiting', 'Awaiting confirm', Job::query()
                ->where('calibration_status', CalibrationStatus::Completed->value)
                ->whereNull('calibration_confirmed_at')
                ->count()),
            $this->tabAction('completed', 'Completed'),
            $this->tabAction('all', 'All'),
        ];
    }

    private function tabAction(string $key, string $label, ?int $badge = null): Action
    {
        return Action::make('tab_'.$key)
            ->label($label)
            ->color(fn () => $this->view_tab === $key ? 'primary' : 'gray')
            ->badge($badge && $badge > 0 ? $badge : null)
            ->action(function () use ($key) {
                $this->view_tab = $key;
                $this->resetTable();
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->tableQuery())
            ->defaultSort('calibration_status')
            ->columns([
                TextColumn::make('job_number')
                    ->label('Job')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('customer_name_snapshot')
                    ->label('Name')
                    ->searchable()
                    ->description(fn (Job $r) => $r->vehicleLabel() ?: null),
                TextColumn::make('city')
                    ->label('City')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('service_address')
                    ->label('Address')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vin')
                    ->label('VIN')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable(),
                TextColumn::make('part_number')
                    ->label('WS Part #')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('entity.code')
                    ->label('Loc')
                    ->badge(),
                TextColumn::make('calibration_status')
                    ->label('Status')
                    ->formatStateUsing(fn ($state) => $state instanceof CalibrationStatus
                        ? $state->label()
                        : '—')
                    ->badge()
                    ->color(fn ($state) => $state instanceof CalibrationStatus
                        ? $state->color()
                        : 'gray'),
                TextColumn::make('calibration_scheduled_at')
                    ->label('Cal. appointment')
                    ->state(function (Job $record) {
                        $local = Timezone::toDisplay($record->calibration_scheduled_at);

                        return $local?->format('M j · g:ia') ?? '—';
                    }),
            ])
            ->filters([
                SelectFilter::make('calibration_status')
                    ->label('Status')
                    ->options(collect(CalibrationStatus::cases())
                        ->mapWithKeys(fn (CalibrationStatus $s) => [$s->value => $s->label()])
                        ->all()),
            ])
            ->recordActions([
                $this->viewSummaryAction(),
                $this->scheduleAction(),
                $this->markCompleteAction(),
                $this->confirmAction(),
            ])
            ->emptyStateHeading('No calibration jobs')
            ->emptyStateDescription('Jobs appear here after the windshield install is marked complete when calibration is needed (all insurance jobs, or manual flag at intake).')
            ->emptyStateIcon(Heroicon::OutlinedWrenchScrewdriver);
    }

    private function tableQuery(): Builder
    {
        $q = Job::query()
            ->whereNotNull('calibration_status')
            ->with(['entity', 'customer']);

        return match ($this->view_tab) {
            'needed' => $q->where('calibration_status', CalibrationStatus::Needed->value),
            'scheduled' => $q->where('calibration_status', CalibrationStatus::Scheduled->value),
            'awaiting' => $q
                ->where('calibration_status', CalibrationStatus::Completed->value)
                ->whereNull('calibration_confirmed_at'),
            'completed' => $q
                ->where('calibration_status', CalibrationStatus::Completed->value)
                ->whereNotNull('calibration_confirmed_at'),
            'all' => $q,
            default => $q->whereIn('calibration_status', [
                CalibrationStatus::Needed->value,
                CalibrationStatus::Scheduled->value,
            ]),
        };
    }

    private function canPartnerAct(): bool
    {
        $user = auth()->user();

        return ($user?->can('manage_calibration') || $user?->can('manage_jobs')) ?? false;
    }

    private function canConfirm(): bool
    {
        return auth()->user()?->can('manage_jobs') ?? false;
    }

    private function calibration(): CalibrationService
    {
        return app(CalibrationService::class);
    }

    private function viewSummaryAction(): Action
    {
        // Placeholders (not infolist TextEntry + enum type-hints): Filament was
        // DI-resolving CalibrationStatus on color/format callbacks and fatalling
        // with "Target [CalibrationStatus] is not instantiable".
        return Action::make('viewSummary')
            ->label('View')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->modalHeading(fn (Job $record) => 'Calibration — '.$record->job_number)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->schema([
                Placeholder::make('job_number')
                    ->label('Job #')
                    ->content(fn ($record) => $record instanceof Job ? $record->job_number : '—'),
                Placeholder::make('location')
                    ->label('Location')
                    ->content(fn ($record) => $record instanceof Job ? ($record->entity?->code ?? '—') : '—'),
                Placeholder::make('name')
                    ->label('Name')
                    ->content(fn ($record) => $record instanceof Job ? ($record->customer_name_snapshot ?: '—') : '—'),
                Placeholder::make('vehicle')
                    ->label('Vehicle')
                    ->content(fn ($record) => $record instanceof Job ? ($record->vehicleLabel() ?: '—') : '—'),
                Placeholder::make('city')
                    ->label('City')
                    ->content(fn ($record) => $record instanceof Job ? ($record->city ?: '—') : '—'),
                Placeholder::make('address')
                    ->label('Address')
                    ->content(fn ($record) => $record instanceof Job ? ($record->service_address ?: '—') : '—'),
                Placeholder::make('vin')
                    ->label('VIN')
                    ->content(fn ($record) => $record instanceof Job ? ($record->vin ?: '—') : '—'),
                Placeholder::make('part')
                    ->label('WS Part #')
                    ->content(fn ($record) => $record instanceof Job ? ($record->part_number ?: '—') : '—'),
                Placeholder::make('phone')
                    ->label('Phone')
                    ->content(fn ($record) => $record instanceof Job
                        ? ($record->customer?->phone_e164 ?: $record->customer_phone_snapshot ?: '—')
                        : '—'),
                Placeholder::make('status')
                    ->label('Status')
                    ->content(fn ($record) => $record instanceof Job
                        ? ($record->calibration_status?->label() ?? '—')
                        : '—'),
                Placeholder::make('cal_when')
                    ->label('Cal. appointment')
                    ->content(function ($record) {
                        if (! $record instanceof Job) {
                            return '—';
                        }
                        $local = Timezone::toDisplay($record->calibration_scheduled_at);

                        return $local?->format('D, M j · g:ia') ?? '—';
                    }),
                Placeholder::make('invoice')
                    ->label('Invoice PDF')
                    ->content(fn ($record) => $record instanceof Job && filled($record->calibration_invoice_path)
                        ? 'Uploaded' : '—'),
                Placeholder::make('report')
                    ->label('Calibration report PDF')
                    ->content(fn ($record) => $record instanceof Job && filled($record->calibration_report_path)
                        ? 'Uploaded' : '—'),
            ]);
    }

    private function scheduleAction(): Action
    {
        return Action::make('scheduleCalibration')
            ->label(fn (Job $record) => $record->calibration_status === CalibrationStatus::Scheduled
                ? 'Reschedule'
                : 'Schedule calibration')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color(fn (Job $record) => $record->calibration_status === CalibrationStatus::Needed
                ? 'warning'
                : 'gray')
            ->visible(fn (Job $record) => $this->canPartnerAct()
                && $record->calibration_status !== null
                && $record->calibration_status !== CalibrationStatus::Completed)
            ->fillForm(function (Job $record) {
                $local = Timezone::toDisplay($record->calibration_scheduled_at);

                return [
                    'scheduled_date' => $local?->format('Y-m-d'),
                    'scheduled_time' => $local?->format('H:i'),
                ];
            })
            ->schema([
                DatePicker::make('scheduled_date')
                    ->label('Calibration date (Pacific)')
                    ->native(false)
                    ->required(),
                TimePicker::make('scheduled_time')
                    ->label('Calibration time (Pacific)')
                    ->seconds(false)
                    ->required(),
            ])
            ->action(function (Job $record, array $data) {
                $date = Carbon::parse($data['scheduled_date'], Timezone::display())->format('Y-m-d');
                $time = Carbon::parse($data['scheduled_time'])->format('H:i');
                $scheduledLocal = Carbon::parse("{$date} {$time}", Timezone::display());

                $this->calibration()->schedule($record, $scheduledLocal, auth()->user());

                Notification::make()->title('Calibration scheduled')->success()->send();
            });
    }

    private function markCompleteAction(): Action
    {
        return Action::make('markCalibrationComplete')
            ->label('Mark as completed')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Job $record) => $this->canPartnerAct()
                && in_array($record->calibration_status, [
                    CalibrationStatus::Needed,
                    CalibrationStatus::Scheduled,
                ], true))
            ->modalHeading('Mark calibration complete')
            ->modalDescription('Upload both PDFs — invoice and calibration report. Both are required.')
            ->schema([
                // storeFiles(false): keep the Livewire temp object until we
                // permanently store under calibration/{job_id}/ ourselves. Avoids
                // Filament moving files mid-request on a disk that may not have
                // the directory yet; pairs with the tmpfile() polyfill on Cloudways.
                FileUpload::make('invoice')
                    ->label('Invoice (PDF)')
                    ->acceptedFileTypes(['application/pdf'])
                    ->storeFiles(false)
                    ->required()
                    ->maxSize(10240),
                FileUpload::make('report')
                    ->label('Calibration report (PDF)')
                    ->acceptedFileTypes(['application/pdf'])
                    ->storeFiles(false)
                    ->required()
                    ->maxSize(10240),
            ])
            ->action(function (Job $record, array $data) {
                try {
                    $invoicePath = $this->storeCalibrationPdf($record, $data['invoice'] ?? null, 'invoice');
                    $reportPath = $this->storeCalibrationPdf($record, $data['report'] ?? null, 'report');
                } catch (\Throwable $e) {
                    Notification::make()->title('PDF upload failed')
                        ->body($e->getMessage())
                        ->danger()->send();

                    return;
                }

                if (blank($invoicePath) || blank($reportPath)) {
                    Notification::make()->title('Both PDFs are required')
                        ->warning()->send();

                    return;
                }

                $this->calibration()->markComplete(
                    $record,
                    $invoicePath,
                    $reportPath,
                    auth()->user(),
                );

                Notification::make()
                    ->title('Calibration marked complete')
                    ->body('Ops has been notified on Telegram.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Persist a Livewire TemporaryUploadedFile (or already-stored path) under
     * storage/app/private/calibration/{jobId}/ as a PDF.
     */
    private function storeCalibrationPdf(Job $record, mixed $upload, string $basename): ?string
    {
        if ($upload === null || $upload === '' || $upload === []) {
            return null;
        }

        // FileUpload may wrap a single file in an array.
        if (is_array($upload)) {
            $upload = $upload[0] ?? null;
        }

        if ($upload === null || $upload === '') {
            return null;
        }

        $directory = 'calibration/'.$record->id;
        $filename = $basename.'.pdf';

        if ($upload instanceof TemporaryUploadedFile) {
            $path = $upload->storeAs($directory, $filename, 'local');

            return is_string($path) ? str_replace('\\', '/', $path) : null;
        }

        // Already a relative path on the local disk (re-open / re-submit).
        if (is_string($upload)) {
            return $this->normalizeStoredPath($upload);
        }

        return null;
    }

    private function confirmAction(): Action
    {
        return Action::make('confirmCalibration')
            ->label('Confirm (thumbs)')
            ->icon(Heroicon::OutlinedHandThumbUp)
            ->color('primary')
            ->visible(fn (Job $record) => $this->canConfirm()
                && $record->calibration_status === CalibrationStatus::Completed
                && ! $record->isCalibrationConfirmed())
            ->modalHeading('How did the calibration go?')
            ->modalDescription('Recorded either way. The customer gets a final "calibration complete" text, and dispatch is asked to call them for a review — same as any other completed job.')
            ->schema([
                Radio::make('job_sentiment')
                    ->label('How did the calibration go?')
                    ->options([
                        'up' => '👍 Went well',
                        'down' => '👎 Not great',
                    ])
                    ->required(),
            ])
            ->action(function (Job $record, array $data) {
                $wentWell = ($data['job_sentiment'] ?? null) === 'up';

                $this->calibration()->confirm($record, $wentWell, auth()->user());

                Notification::make()
                    ->title('Calibration confirmed')
                    ->body('Final text sent. Call the customer for a review — track it on the Reviews dashboard.')
                    ->success()
                    ->send();
            });
    }

    private function normalizeStoredPath(string $path): string
    {
        $root = rtrim(Storage::disk('local')->path(''), DIRECTORY_SEPARATOR);
        $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        if (str_starts_with($normalized, $root)) {
            $relative = ltrim(substr($normalized, strlen($root)), DIRECTORY_SEPARATOR);

            return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        }

        return str_replace('\\', '/', $path);
    }
}
