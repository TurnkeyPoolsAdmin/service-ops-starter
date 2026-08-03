<?php

namespace App\Filament\Pages;

use App\Enums\PartsSupplier;
use App\Filament\Resources\Jobs\JobResource;
use App\Models\Job;
use App\Models\VendorDocument;
use App\Models\VendorDocumentLine;
use App\Models\VendorImport;
use App\Services\Vendors\ChargeResolution;
use App\Services\Vendors\Igc\IgcChargeReconciler;
use App\Services\Vendors\Igc\IgcChargeResolution;
use App\Services\Vendors\Igc\IgcInvoiceHistoryImporter;
use App\Services\Vendors\Igc\IgcNetter;
use App\Services\Vendors\Mygrant\CreditMemoImporter;
use App\Services\Vendors\Mygrant\OrderHistoryImporter;
use App\Services\Vendors\VendorChargeReconciler;
use App\Services\Vendors\VendorLineMatcher;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Admin > Finance > "Parts Invoices" — the Mygrant reconciliation workbench.
 * Upload the two vendor feeds, see the current billing cycle's charge status
 * at a glance, and work the "lines to match" queue down to zero. Gated so
 * BOTH admin (access_admin) and the finance role (use_bank_ledger, which
 * doesn't carry access_admin) can reach it — mirrors BankTransactionResource's
 * own gate in spirit, since this page is half bookkeeping (charge <-> document)
 * and half ops (line <-> job).
 */
class PartsInvoices extends Page implements HasTable
{
    use InteractsWithTable;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->can('access_admin') ?? false) || ($user?->can('use_bank_ledger') ?? false);
    }

    // Reached via the Admin > Finance hub, not its own sidebar item.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected string $view = 'filament.pages.parts-invoices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $title = 'Parts Invoices';

    protected static ?string $navigationLabel = 'Parts Invoices';

    protected static ?int $navigationSort = 27;

    /** Which supplier tab is showing: mygrant | igc. */
    #[Url]
    public string $supplier = 'mygrant';

    public function mount(): void
    {
        if (! in_array($this->supplier, ['mygrant', 'igc'], true)) {
            $this->supplier = 'mygrant';
        }
    }

    /** @return array<int, array{key: string, label: string, active: bool}> */
    public function supplierTabs(): array
    {
        return [
            ['key' => 'mygrant', 'label' => PartsSupplier::Mygrant->label(), 'active' => $this->supplier === 'mygrant'],
            ['key' => 'igc', 'label' => PartsSupplier::Igc->label(), 'active' => $this->supplier === 'igc'],
        ];
    }

    public function switchSupplier(string $key): void
    {
        if (! in_array($key, ['mygrant', 'igc'], true)) {
            return;
        }

        $this->supplier = $key;
        $this->flushCachedTableRecords();
    }

    public function getSubheading(): ?string
    {
        return $this->supplier === 'igc'
            ? 'IGC invoice history, reconciled against the Chase card charge and netted against returns.'
            : 'Mygrant order history + credit memos, reconciled against the Chase card charge and against jobs.';
    }

    // ---- Status banner ----

    /**
     * @return array{state: string, resolution: ?ChargeResolution}
     */
    public function chargeStatus(): array
    {
        $resolution = app(VendorChargeReconciler::class)->latestChargeResolution();

        return [
            'state' => $resolution?->state ?? 'none',
            'resolution' => $resolution,
        ];
    }

    // ---- Documents list ----

    public function documents(): EloquentCollection
    {
        return VendorDocument::query()
            ->mygrant()
            ->orderByDesc('cut_date')
            ->limit(30)
            ->get();
    }

    /**
     * Open documents that settle before the bank ledger's own Mygrant
     * coverage started — they have no charge and never will (Plaid's history
     * doesn't reach back that far). Surfaced on its own so they read as
     * "explained" rather than just sitting in the open queue forever.
     */
    public function outOfScopeDocuments(): Collection
    {
        return app(VendorChargeReconciler::class)->outOfScopeDocuments();
    }

    // ---- Variances list ----

    public function variances(): EloquentCollection
    {
        return VendorDocumentLine::query()
            ->where('match_status', VendorDocumentLine::MATCH_MATCHED)
            ->whereNotNull('variance_cents')
            ->where('variance_cents', '!=', 0)
            ->with('job')
            ->orderByDesc('matched_at')
            ->limit(30)
            ->get();
    }

    // ---- IGC tab ----

    /** @return array{state: string, resolution: ?IgcChargeResolution} */
    public function igcChargeStatus(): array
    {
        $resolution = app(IgcChargeReconciler::class)->latestChargeResolution();

        return [
            'state' => $resolution?->state ?? 'none',
            'resolution' => $resolution,
        ];
    }

    public function igcDocuments(): EloquentCollection
    {
        return VendorDocument::query()
            ->igc()
            ->orderByDesc('document_date')
            ->limit(30)
            ->get();
    }

    /** Layer 5 aging (build spec §7) — same query as IgcAgingAlert, for on-page display. */
    public function igcAgingDocuments(): EloquentCollection
    {
        $days = (int) config('glass.vendors.igc.aging_alert_days', 30);
        $cutoff = now()->subDays($days)->toDateString();

        return VendorDocument::query()->igc()->invoices()
            ->whereNotNull('balance_cents')
            ->where('balance_cents', '!=', 0)
            ->where('document_date', '<=', $cutoff)
            ->orderBy('document_date')
            ->get();
    }

    /** @return array{paired: int, unpaired: int} */
    public function igcNettingSummary(): array
    {
        return app(IgcNetter::class)->run();
    }

    // ---- Header actions ----

    protected function getHeaderActions(): array
    {
        return [
            $this->uploadOrderHistoryAction(),
            $this->uploadCreditMemoAction(),
            $this->uploadIgcInvoiceHistoryAction(),
            $this->reconcileNowAction(),
            $this->igcReconcileNowAction(),
            $this->acceptDifferenceAction(),
        ];
    }

    private function uploadOrderHistoryAction(): Action
    {
        return Action::make('uploadOrderHistory')
            ->label('Upload order history (CSV)')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn () => $this->supplier === 'mygrant')
            ->schema([
                FileUpload::make('file')
                    ->label('Order history CSV')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data) {
                $upload = is_array($data['file']) ? ($data['file'][0] ?? null) : $data['file'];
                if ($upload === null) {
                    return;
                }

                $contents = file_get_contents($upload->getRealPath());
                $import = app(OrderHistoryImporter::class)->import($contents, auth()->user(), $upload->getClientOriginalName());

                $this->notifyImportResult($import);
            });
    }

    private function uploadCreditMemoAction(): Action
    {
        return Action::make('uploadCreditMemo')
            ->label('Upload credit memo (PDF)')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn () => $this->supplier === 'mygrant')
            ->schema([
                FileUpload::make('file')
                    ->label('Credit memo PDF')
                    ->acceptedFileTypes(['application/pdf'])
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data) {
                $upload = is_array($data['file']) ? ($data['file'][0] ?? null) : $data['file'];
                if ($upload === null) {
                    return;
                }

                $contents = file_get_contents($upload->getRealPath());
                $import = app(CreditMemoImporter::class)->import($contents, auth()->user(), $upload->getClientOriginalName());

                $this->notifyImportResult($import);
            });
    }

    /**
     * IGC's invoice-history XLSX. Unlike the Mygrant CSV/PDF importers,
     * IgcInvoiceHistoryImporter reads via openspout, which needs a real file
     * PATH — so this passes getRealPath() straight through instead of the
     * file_get_contents() round-trip the other two uploads use.
     *
     * There is deliberately no "Upload order history" action on this tab —
     * App\Services\Vendors\Igc\IgcOrderHistoryImporter is DEFERRED (we don't
     * have that export file yet, see the build spec §3). Add its upload
     * action here alongside this one when that importer ships.
     */
    private function uploadIgcInvoiceHistoryAction(): Action
    {
        return Action::make('uploadIgcInvoiceHistory')
            ->label('Upload invoice history (XLSX)')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn () => $this->supplier === 'igc')
            ->schema([
                FileUpload::make('file')
                    ->label('IGC Invoice Payment/Returns History (XLSX)')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data) {
                $upload = is_array($data['file']) ? ($data['file'][0] ?? null) : $data['file'];
                if ($upload === null) {
                    return;
                }

                $import = app(IgcInvoiceHistoryImporter::class)->import($upload->getRealPath(), auth()->user(), $upload->getClientOriginalName());

                $this->notifyImportResult($import);
            });
    }

    private function notifyImportResult(VendorImport $import): void
    {
        if ($import->result === 'ok') {
            $body = "{$import->lines_parsed} line(s) parsed, {$import->lines_created} new, "
                ."{$import->lines_updated} updated, {$import->documents_touched} document(s) touched.";
            if ($import->error) {
                // A successful import can still carry warnings (e.g. an
                // unresolved DR# reference on a credit memo) — surface them.
                $body .= ' Warnings: '.$import->error;
            }

            Notification::make()->title('Import complete')->body($body)->success()->send();

            // Fresh data landed — immediately re-attempt reconciliation rather
            // than waiting for tomorrow's cron.
            if ($import->supplier === PartsSupplier::Igc->value) {
                app(IgcNetter::class)->run();
                app(IgcChargeReconciler::class)->run();
            } else {
                app(VendorChargeReconciler::class)->run();
                app(VendorLineMatcher::class)->run();
            }

            return;
        }

        // The credit-memo importer deliberately REFUSES a memo whose lines
        // don't sum to its own stated net rather than silently corrupting the
        // ledger — surface that reason plainly instead of swallowing it.
        Notification::make()
            ->title('Import failed')
            ->body($import->error ?: 'Unknown error.')
            ->danger()
            ->persistent()
            ->send();
    }

    private function reconcileNowAction(): Action
    {
        return Action::make('reconcileNow')
            ->label('Reconcile now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn () => $this->supplier === 'mygrant')
            ->action(function () {
                $charges = app(VendorChargeReconciler::class)->run();
                $lines = app(VendorLineMatcher::class)->run();

                Notification::make()
                    ->title('Reconciled')
                    ->body('Charges: '.$this->formatTally($charges).' — Lines: '.$this->formatTally($lines))
                    ->success()
                    ->send();
            });
    }

    private function igcReconcileNowAction(): Action
    {
        return Action::make('igcReconcileNow')
            ->label('Reconcile now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn () => $this->supplier === 'igc')
            ->action(function () {
                $net = app(IgcNetter::class)->run();
                $charges = app(IgcChargeReconciler::class)->run();

                Notification::make()
                    ->title('Reconciled')
                    ->body('Netting: '.$this->formatTally($net).' — Charges: '.$this->formatTally($charges))
                    ->success()
                    ->send();
            });
    }

    private function formatTally(array $tally): string
    {
        $parts = [];
        foreach ($tally as $state => $count) {
            if ($count > 0) {
                $parts[] = "{$count} {$state}";
            }
        }

        return $parts === [] ? 'nothing to do' : implode(', ', $parts);
    }

    /** Closing an old short week with no credit memo available. */
    private function acceptDifferenceAction(): Action
    {
        return Action::make('acceptDifference')
            ->label('Accept the difference')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('warning')
            ->visible(fn () => $this->supplier === 'mygrant'
                && ($this->chargeStatus()['resolution']?->state ?? null) === 'short')
            ->modalDescription('For an old week where the credit memo will never be uploaded — accepts the '
                .'shortfall on one document so the charge can match.')
            ->schema([
                Select::make('vendor_document_id')
                    ->label('Which document?')
                    ->options(fn () => VendorDocument::query()->mygrant()->invoices()->unpaid()
                        ->orderByDesc('cut_date')->limit(20)->get()
                        ->mapWithKeys(fn (VendorDocument $d) => [$d->id => $d->documentLabel().' — '.Money::format($d->net_amount_cents)])
                        ->all())
                    ->required()
                    ->searchable(),
                Textarea::make('note')->label('Why (required)')->required()->rows(2),
            ])
            ->action(function (array $data) {
                $document = VendorDocument::find($data['vendor_document_id']);
                $resolution = $this->chargeStatus()['resolution'];

                if ($document === null || $resolution === null || $resolution->shortfallCents === null) {
                    Notification::make()->title('Nothing to accept')->warning()->send();

                    return;
                }

                app(VendorChargeReconciler::class)->acceptDifference(
                    $document,
                    -$resolution->shortfallCents,
                    $data['note'],
                    auth()->user(),
                );

                app(VendorChargeReconciler::class)->resolve($resolution->transaction);

                Notification::make()->title('Difference accepted')->success()->send();
            });
    }

    // ---- Lines-to-match table ----

    public function table(Table $table): Table
    {
        return $table
            // Every BILLED line, narrowed by the "Show" filter below, which
            // defaults to the needs-a-job queue. The base query used to BE
            // needsJob(), which meant a line filed as no_job (shop supplies, or
            // now pre-tracking) vanished with no way to see it or take it back.
            // On vendor money data, out of the queue must not mean unreachable.
            ->query(
                VendorDocumentLine::query()
                    ->billed()
                    ->with(['suggestedJob', 'warehouse', 'vendorDocument'])
            )
            ->filters([
                SelectFilter::make('line_view')
                    ->label('Show')
                    ->options([
                        'needs_job' => 'Needs a job',
                        'no_job' => 'Filed as no job',
                        'matched' => 'Matched',
                        'all' => 'All billed lines',
                    ])
                    ->default('needs_job')
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? 'needs_job') {
                        'no_job' => $query->where('match_status', VendorDocumentLine::MATCH_NO_JOB),
                        'matched' => $query->where('match_status', VendorDocumentLine::MATCH_MATCHED),
                        'all' => $query,
                        default => $query->whereIn('match_status', [
                            VendorDocumentLine::MATCH_UNMATCHED,
                            VendorDocumentLine::MATCH_SUGGESTED,
                        ]),
                    }),
            ])
            ->defaultSort('confirmed_date', 'desc')
            ->columns([
                TextColumn::make('part_number')->label('Part')
                    ->description(fn (VendorDocumentLine $r) => $r->part_description ? Str::limit($r->part_description, 40) : null),
                TextColumn::make('vehicle')->label('Vehicle')
                    ->state(fn (VendorDocumentLine $r) => self::vehicleLabel($r)),
                TextColumn::make('confirmed_date')->label('Date')
                    ->state(fn (VendorDocumentLine $r) => $r->confirmed_date ?: $r->order_date)
                    ->sortable(),
                TextColumn::make('line_total_cents')->label('Amount')->alignEnd()
                    ->formatStateUsing(fn (int $state) => Money::format($state)),
                TextColumn::make('warehouse.name')->label('Warehouse')->placeholder('—'),
                TextColumn::make('suggestedJob.job_number')->label('Suggested job')
                    ->url(fn (VendorDocumentLine $r) => $r->suggested_job_id ? JobResource::getUrl('view', ['record' => $r->suggested_job_id]) : null)
                    ->color(fn (VendorDocumentLine $r) => $r->suggested_job_id ? 'primary' : null)
                    ->placeholder('—'),
                TextColumn::make('match_status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst(str_replace('_', ' ', $state)))
                    // WHY it was filed as no_job — 'pre_job_tracking',
                    // 'shop_supplies', or whatever a human typed. Without this
                    // the status is a verdict with no stated reason.
                    ->description(fn (VendorDocumentLine $r) => $r->no_job_reason
                        ? ucfirst(str_replace('_', ' ', $r->no_job_reason))
                        : null)
                    ->color(fn (VendorDocumentLine $r) => match ($r->match_status) {
                        VendorDocumentLine::MATCH_SUGGESTED => 'warning',
                        VendorDocumentLine::MATCH_MATCHED => 'success',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                $this->confirmSuggestionAction(),
                $this->matchToJobAction(),
                $this->noJobAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('confirmSuggestions')
                        ->label('Confirm suggested matches')
                        ->icon(Heroicon::OutlinedCheck)
                        ->action(function (EloquentCollection $records) {
                            $matcher = app(VendorLineMatcher::class);
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->match_status === VendorDocumentLine::MATCH_SUGGESTED) {
                                    $matcher->confirmSuggestion($record, auth()->user());
                                    $count++;
                                }
                            }

                            Notification::make()->title("Confirmed {$count} match(es)")->success()->send();
                        }),
                ]),
            ])
            ->emptyStateHeading('Nothing to match')
            ->emptyStateDescription('Every billed Mygrant line has a job (or was routed to shop supplies / no job).')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    private function confirmSuggestionAction(): Action
    {
        return Action::make('confirmSuggestion')
            ->label('Confirm')
            ->tooltip('Confirm the suggested job')
            ->iconButton()
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->visible(fn (VendorDocumentLine $r) => $r->match_status === VendorDocumentLine::MATCH_SUGGESTED)
            ->action(function (VendorDocumentLine $record) {
                app(VendorLineMatcher::class)->confirmSuggestion($record, auth()->user());

                Notification::make()->title('Match confirmed')->success()->send();
            });
    }

    private function matchToJobAction(): Action
    {
        return Action::make('matchToJob')
            ->label('Match to job')
            ->tooltip('Match to job')
            ->iconButton()
            ->icon(Heroicon::OutlinedLink)
            ->schema([
                Select::make('job_id')->label('Job')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Job::query()
                        ->where(fn (Builder $q) => $q->where('job_number', 'like', "%{$search}%")
                            ->orWhere('customer_name_snapshot', 'like', "%{$search}%"))
                        ->orderByDesc('created_at')->limit(25)->get()
                        ->mapWithKeys(fn (Job $j) => [$j->id => $j->job_number.' — '.$j->customer_name_snapshot])->all())
                    ->getOptionLabelsUsing(fn (array $values) => Job::findMany($values)
                        ->mapWithKeys(fn (Job $j) => [$j->id => $j->job_number.' — '.$j->customer_name_snapshot])->all())
                    ->required(),
            ])
            ->action(function (VendorDocumentLine $record, array $data) {
                $job = Job::find($data['job_id']);
                if ($job === null) {
                    return;
                }

                app(VendorLineMatcher::class)->matchToJob($record, $job, auth()->user());

                Notification::make()->title('Matched to job')->success()->send();
            });
    }

    private function noJobAction(): Action
    {
        return Action::make('noJob')
            ->label('No job')
            ->tooltip('No job applies')
            ->iconButton()
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('gray')
            ->schema([
                Radio::make('reason')->label('Why')
                    ->options(['shop_supplies' => 'Shop supplies', 'other' => 'Other (accept — no job applies)'])
                    ->default('shop_supplies')
                    ->required(),
            ])
            ->action(function (VendorDocumentLine $record, array $data) {
                app(VendorLineMatcher::class)->markNoJob($record, $data['reason'], auth()->user());

                Notification::make()->title('Marked no job')->success()->send();
            });
    }

    private static function vehicleLabel(VendorDocumentLine $r): string
    {
        if ($r->vehicle_years_from === null) {
            return $r->vehicle_make ? $r->vehicle_make : '—';
        }

        $years = $r->vehicle_years_to && $r->vehicle_years_to !== $r->vehicle_years_from
            ? "{$r->vehicle_years_from}-{$r->vehicle_years_to}"
            : (string) $r->vehicle_years_from;

        return trim("{$years} {$r->vehicle_make} {$r->vehicle_model}");
    }
}
