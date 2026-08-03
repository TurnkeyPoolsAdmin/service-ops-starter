<?php

namespace App\Filament\Resources\BankTransactions\Tables;

use App\Enums\BankMatchKind;
use App\Enums\IssueType;
use App\Enums\PartsSupplier;
use App\Enums\PaymentMethod;
use App\Filament\Pages\TechPayments;
use App\Filament\Resources\Jobs\JobResource;
use App\Models\BankTransaction;
use App\Models\BankTransactionMatch;
use App\Models\Employee;
use App\Models\Issue;
use App\Models\Job;
use App\Models\JobPayment;
use App\Models\TechSettlement;
use App\Models\VendorDocument;
use App\Models\VendorPayment;
use App\Models\Warehouse;
use App\Services\BankMatcher;
use App\Services\LlmMatchSuggester;
use App\Services\Vendors\Igc\IgcChargeReconciler;
use App\Services\Vendors\VendorChargeReconciler;
use App\Support\Money;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class BankTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            // Everything matchStatusHtml() links to AND everything
            // BankTransactionMatch::describe() reads for the summary line —
            // without these the "Matched to" column is an N+1 per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'matches.job',
                'matches.techSettlement.technician',
                'matches.legacyManualPayment',
                'matches.vendorDocument',
            ]))
            // Flagged rows glow amber — but only for the super_admin, per request.
            ->recordClasses(fn (BankTransaction $r) => $r->review_flagged && self::viewerIsSuperAdmin()
                ? 'gd-flagged-row'
                : null)
            ->columns([
                // Plain TextColumn (not IconColumn) — Filament's IconColumn treats any
                // boolean-cast attribute as a "boolean column" and falls back to its
                // own default false-state icon (a circled X) no matter what the icon()
                // closure returns, so it can't be coaxed into rendering nothing. A
                // literal emoji sidesteps Filament's icon rendering entirely.
                TextColumn::make('review_flagged')->label('')
                    ->formatStateUsing(fn (bool $state) => $state ? '🚩' : '')
                    ->tooltip(fn (BankTransaction $r) => $r->review_flagged
                        ? ('Flagged for review'.($r->review_note ? ': '.$r->review_note : ''))
                        : null),
                // Posted date. When Plaid gave us an initiation date that differs
                // (e.g. a Zelle sent Jul 10 that posts Jul 13), append a small
                // "init n/j" hint and spell it out in the tooltip — that earlier
                // date is what reconciliation keys on (BankTransaction::matchDate).
                TextColumn::make('date')->label('Date')
                    ->formatStateUsing(function (?string $state, BankTransaction $r) {
                        $posted = $state ? Carbon::parse($state)->format('n/j/y') : '—';

                        return $r->authorized_date && $r->authorized_date !== $r->date
                            ? new HtmlString(e($posted).' <span class="text-xs text-gray-400">· init '.e(Carbon::parse($r->authorized_date)->format('n/j')).'</span>')
                            : $posted;
                    })
                    ->tooltip(fn (BankTransaction $r) => $r->authorized_date && $r->authorized_date !== $r->date
                        ? 'Initiated '.Carbon::parse($r->authorized_date)->format('n/j/y').', posted '.Carbon::parse($r->date)->format('n/j/y')
                        : null)
                    ->sortable(),
                TextColumn::make('account_name')->label('Account')
                    ->formatStateUsing(fn (?string $state) => self::shortAccount($state))
                    ->tooltip(fn (BankTransaction $r) => trim(($r->account_name ?? '').($r->account_mask ? ' ••'.$r->account_mask : '')))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('name')->label('Description')
                    ->limit(45)
                    ->tooltip(fn (BankTransaction $r) => $r->name)
                    ->searchable(['name', 'merchant_name']),
                TextColumn::make('category')->label('Category')->badge()
                    ->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                // amount_cents is Plaid-signed (positive = money OUT). Display deposit-positive.
                TextColumn::make('amount_cents')->label('Amount')->alignEnd()->sortable()
                    ->formatStateUsing(function (int $state) {
                        $dollars = -$state / 100; // flip to "+ = money in"
                        $sign = $dollars >= 0 ? '+' : '−';

                        return $sign.'$'.number_format(abs($dollars), 2);
                    })
                    ->color(fn (int $state) => $state < 0 ? 'success' : 'gray'),
                TextColumn::make('pending')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Pending' : 'Posted')
                    ->color(fn (bool $state) => $state ? 'warning' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('match_status')->label('Matched to')->badge()
                    ->formatStateUsing(fn (string $state, BankTransaction $r) => new HtmlString(self::matchStatusHtml($state, $r)))
                    // WHAT is matched/suggested, spelled out under the badge. The
                    // badge only ever links to a Job or a tech payout, so without
                    // this a legacy, misc, dividend, vendor or multi-match row is
                    // an inert word with no way to see what it proposes.
                    ->description(fn (BankTransaction $r) => self::matchSummary($r))
                    ->color(fn (string $state) => match ($state) {
                        BankTransaction::STATUS_MATCHED => 'success',
                        BankTransaction::STATUS_SUGGESTED => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('plaid_account_id')
                    ->label('Account')
                    ->options(fn () => BankTransaction::query()
                        ->whereNotNull('account_name')
                        ->distinct()
                        ->get(['plaid_account_id', 'account_name', 'account_mask'])
                        ->mapWithKeys(fn (BankTransaction $r) => [$r->plaid_account_id => self::shortAccount($r->account_name).($r->account_mask ? ' ••'.$r->account_mask : '')])
                        ->all()),
                TernaryFilter::make('pending')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Pending only')
                    ->falseLabel('Posted only'),
                TernaryFilter::make('review_flagged')
                    ->label('Flagged')
                    ->placeholder('All')
                    ->trueLabel('Flagged only')
                    ->falseLabel('Not flagged'),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $q, array $data) {
                        return $q
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('date', '>=', Carbon::parse($d)->format('Y-m-d')))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->where('date', '<=', Carbon::parse($d)->format('Y-m-d')));
                    }),
            ])
            ->recordActions([
                self::confirmAction(),
                self::askAiAction(),
                self::matchAction(),
                self::unmatchAction(),
                self::flagAction(),
                self::editFlagNoteAction(),
                self::unflagAction(),
                self::deleteAction(),
            ]);
    }

    /**
     * Hard-delete a single bank transaction — super_admin only. Plaid rows are
     * normally read-only (the next sync re-imports on cursor), so this is an
     * escape hatch for a genuinely bogus/duplicate row. Clears its matches and
     * any open mismatch Issue first so nothing is left dangling.
     */
    private static function deleteAction(): Action
    {
        return Action::make('delete')
            ->label('Delete transaction')
            ->tooltip('Delete transaction')
            ->iconButton()
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete this transaction?')
            ->modalDescription('This permanently removes the bank transaction and any match on it. A future bank sync may re-import it if Plaid still returns it.')
            ->visible(fn () => self::viewerIsSuperAdmin())
            ->action(function (BankTransaction $record) {
                $record->matches()->delete();
                Issue::clearOpenFor($record, IssueType::TransactionMismatch);
                $record->delete();

                Notification::make()->title('Transaction deleted')->success()->send();
            });
    }

    /** Flag a transaction for a second look, with an optional note. */
    private static function flagAction(): Action
    {
        return Action::make('flag')
            ->label('Flag for review')
            ->tooltip('Flag for review')
            ->iconButton()
            ->icon('heroicon-o-flag')
            ->color('warning')
            ->visible(fn (BankTransaction $r) => ! $r->review_flagged)
            ->schema([
                Textarea::make('review_note')->label('Note (optional)')->rows(2),
            ])
            ->action(function (BankTransaction $record, array $data) {
                $record->update([
                    'review_flagged' => true,
                    'review_note' => $data['review_note'] ?? null,
                    'flagged_at' => now(),
                    'flagged_by' => self::actorId(),
                ]);

                Notification::make()->title('Flagged for review')->success()->send();
            });
    }

    /** Edit the note on an already-flagged transaction. */
    private static function editFlagNoteAction(): Action
    {
        return Action::make('editFlagNote')
            ->label('Edit note')
            ->tooltip('Edit note')
            ->iconButton()
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn (BankTransaction $r) => $r->review_flagged)
            ->fillForm(fn (BankTransaction $r) => ['review_note' => $r->review_note])
            ->schema([
                Textarea::make('review_note')->label('Note')->rows(2),
            ])
            ->action(function (BankTransaction $record, array $data) {
                $record->update(['review_note' => $data['review_note'] ?? null]);

                Notification::make()->title('Note updated')->success()->send();
            });
    }

    private static function unflagAction(): Action
    {
        return Action::make('unflag')
            ->label('Clear flag')
            ->tooltip('Clear flag')
            ->iconButton()
            ->icon('heroicon-o-flag')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (BankTransaction $r) => $r->review_flagged)
            ->action(function (BankTransaction $record) {
                $record->update([
                    'review_flagged' => false,
                    'review_note' => null,
                    'flagged_at' => null,
                    'flagged_by' => null,
                ]);

                Notification::make()->title('Flag cleared')->success()->send();
            });
    }

    /**
     * One-click confirm of what the auto-matcher proposed. Tech payouts are only
     * ever suggested at the exact same-day amount now, so there's no mismatch to
     * explain — confirm just stamps it matched.
     */
    private static function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label('Confirm')
            ->tooltip('Confirm')
            ->iconButton()
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (BankTransaction $r) => $r->match_status === BankTransaction::STATUS_SUGGESTED)
            ->action(function (BankTransaction $record) {
                $record->update([
                    'match_status' => BankTransaction::STATUS_MATCHED,
                    'matched_at' => now(),
                    'matched_by' => self::actorId(),
                ]);

                // A confirmed vendor-invoice suggestion (e.g. the charge
                // reconciler's unique-subset suggestion) stamps its documents
                // charged now that a human has committed to it.
                self::stampVendorDocumentsCharged($record);

                Notification::make()->title('Match confirmed')->success()->send();
            });
    }

    /**
     * Stamp charge_matched_at on every vendor document this transaction is
     * (still) linked to. Also stamps vendor_payments.bank_transaction_id for
     * any IGC payment rows on those same documents (IgcChargeReconciler's
     * R2.3 audit-trail stamp — a no-op for Mygrant documents, which never
     * have vendor_payments rows) so a charge a human confirms from a
     * SUGGESTED state gets the same provenance stamp an auto-matched one
     * gets immediately.
     */
    private static function stampVendorDocumentsCharged(BankTransaction $record): void
    {
        $ids = $record->matches()->where('kind', BankMatchKind::VendorInvoice->value)->whereNotNull('vendor_document_id')->pluck('vendor_document_id');

        if ($ids->isNotEmpty()) {
            VendorDocument::whereIn('id', $ids)->update(['charge_matched_at' => now()]);
            VendorPayment::whereIn('vendor_document_id', $ids)->whereNull('bank_transaction_id')->update(['bank_transaction_id' => $record->id]);
        }
    }

    /**
     * Manual match: pick what this transaction was and link it. When the
     * description is a Zelle to an identifiable technician, the "kind" is
     * locked to Tech payout — it can only ever be an End-of-Day payout — and
     * that tech's own payouts are listed first in the settlement picker.
     *
     * Unlike the auto-matcher, a human can match a same-day tech payout, a
     * customer-payment deposit, or a parts charge that DOESN'T add up exactly —
     * Stripe fees, a partial, a tech advance — as long as they write down why
     * (see the *_mismatch_note fields below). A mismatched save carries the
     * expected amount + note on the match row and raises a TransactionMismatch
     * Issue for a super admin to review (BankTransactionsTable::raiseMismatchIssue).
     */
    private static function matchAction(): Action
    {
        return Action::make('match')
            ->label('Match')
            ->tooltip('Match')
            ->iconButton()
            ->icon('heroicon-o-link')
            ->visible(fn (BankTransaction $r) => $r->match_status !== BankTransaction::STATUS_MATCHED)
            ->schema(function (BankTransaction $record) {
                $tech = app(BankMatcher::class)->techFor($record);
                $isMygrant = ! $tech && app(VendorChargeReconciler::class)->isMygrantCharge($record);
                $isIgc = ! $tech && ! $isMygrant && app(IgcChargeReconciler::class)->isIgcCharge($record);
                $isVendorCharge = $isMygrant || $isIgc;
                $vendorSupplier = match (true) {
                    $isMygrant => PartsSupplier::Mygrant->value,
                    $isIgc => PartsSupplier::Igc->value,
                    default => null,
                };

                return [
                    Select::make('kind')->label('This transaction was a...')
                        ->options($tech
                            ? [BankMatchKind::TechPayout->value => BankMatchKind::TechPayout->label()]
                            : BankMatchKind::options())
                        ->default($tech
                            ? BankMatchKind::TechPayout->value
                            : ($isVendorCharge ? BankMatchKind::VendorInvoice->value : null))
                        ->helperText(match (true) {
                            (bool) $tech => "Looks like a Zelle payment to {$tech->name} — can only be matched to an End-of-Day payout.",
                            $isIgc => 'Looks like an IGC charge — defaulting to Vendor invoice.',
                            $isMygrant => 'Looks like a Mygrant charge — defaulting to Vendor invoice.',
                            default => null,
                        })
                        ->live()->required(),

                    // ---- Vendor invoice: which document(s) this charge covers ----
                    Select::make('vendor_document_ids')
                        ->label('Document(s) this charge covers')
                        ->visible(fn (Get $get) => $get('kind') === BankMatchKind::VendorInvoice->value)
                        ->required(fn (Get $get) => $get('kind') === BankMatchKind::VendorInvoice->value)
                        ->helperText(fn (Get $get) => self::vendorDocumentTallyHelperText($get('vendor_document_ids') ?? [], $record))
                        ->multiple()->live()->searchable()
                        ->options(fn () => self::defaultVendorDocumentOptions($record, $vendorSupplier))
                        ->getSearchResultsUsing(fn (string $search) => self::searchVendorDocumentOptions($search, $record, $vendorSupplier))
                        ->getOptionLabelsUsing(fn (array $values) => VendorDocument::findMany($values)
                            ->mapWithKeys(fn (VendorDocument $d) => [$d->id => self::vendorDocumentLabel($d)])->all()),
                    Textarea::make('vendor_mismatch_note')
                        ->label("Documents don't add up to the charge — explain the difference")
                        ->helperText('e.g. closing out an old week with no credit memo — use "Accept the difference" on Parts Invoices instead when possible.')
                        ->visible(fn (Get $get) => $get('kind') === BankMatchKind::VendorInvoice->value
                            && self::isVendorDocumentSumMismatch($get('vendor_document_ids') ?? [], $record))
                        ->required(fn (Get $get) => $get('kind') === BankMatchKind::VendorInvoice->value
                            && self::isVendorDocumentSumMismatch($get('vendor_document_ids') ?? [], $record))
                        ->rows(2),

                    // ---- Customer payment: which payment LINE(S) this deposit covers ----
                    // A job can carry several lines (deposit + balance, maybe different
                    // methods), so the picker works over lines, not whole jobs — a
                    // Stripe payout offers only Stripe-method lines.
                    Select::make('job_payment_ids')
                        ->label('Payment line(s) this deposit covers')
                        ->visible(fn (Get $get) => $get('kind') === BankMatchKind::JobPayment->value)
                        ->required(fn (Get $get) => $get('kind') === BankMatchKind::JobPayment->value)
                        ->helperText(fn (Get $get) => self::depositTallyHelperText($get('job_payment_ids') ?? [], $record))
                        ->multiple()->live()->searchable()
                        // Pre-loaded so the common case (today's deposit, today's
                        // payment lines) never needs typing; getSearchResultsUsing
                        // takes over once the human actually searches.
                        ->options(fn (Get $get) => self::defaultPaymentLineOptions($record, $get('job_payment_ids') ?? []))
                        ->getSearchResultsUsing(fn (string $search) => self::searchPaymentLineOptions($search, $record))
                        ->getOptionLabelsUsing(fn (array $values) => JobPayment::with('job')->findMany($values)
                            ->mapWithKeys(fn (JobPayment $l) => [$l->id => self::isStripeDeposit($record) ? self::cardLineLabel($l) : self::paymentLineLabel($l, null, $record)])->all()),
                    Textarea::make('payment_mismatch_note')
                        ->label("Payment lines don't add up to the deposit — explain the difference")
                        ->helperText('e.g. Stripe fee taken out of the payout, or a partial payment.')
                        ->visible(fn (Get $get) => $get('kind') === BankMatchKind::JobPayment->value
                            && self::isPaymentLineSumMismatch($get('job_payment_ids') ?? [], $record))
                        ->required(fn (Get $get) => $get('kind') === BankMatchKind::JobPayment->value
                            && self::isPaymentLineSumMismatch($get('job_payment_ids') ?? [], $record))
                        ->rows(2),

                    // ---- Parts purchase: warehouse first (filters the job list), then job(s) ----
                    Select::make('warehouse')
                        ->label('Warehouse / supplier')
                        ->visible(fn (Get $get) => $get('kind') === BankMatchKind::PartPurchase->value)
                        ->options(fn () => self::warehouseSelectOptions())
                        ->default(fn () => self::resolveWarehouseFromDescription($record))
                        ->live(),
                    Select::make('job_ids')
                        ->label('Job(s) the parts were for')
                        ->visible(fn (Get $get) => $get('kind') === BankMatchKind::PartPurchase->value)
                        ->required(fn (Get $get) => $get('kind') === BankMatchKind::PartPurchase->value)
                        ->helperText(fn (Get $get) => self::partsTallyHelperText($get('job_ids') ?? [], $record))
                        ->multiple()->live()->searchable()
                        ->options(fn (Get $get) => self::defaultPartJobOptions($get('warehouse'), $record, $get('job_ids') ?? []))
                        ->getSearchResultsUsing(fn (string $search, Get $get) => self::jobOptions($search, $get('warehouse'), $record))
                        ->getOptionLabelsUsing(fn (array $values) => Job::findMany($values)
                            ->mapWithKeys(fn (Job $j) => [$j->id => self::partJobLabel($j)])->all()),
                    Textarea::make('parts_mismatch_note')
                        ->label("Jobs don't add up to the charge — explain the difference")
                        ->visible(fn (Get $get) => $get('kind') === BankMatchKind::PartPurchase->value
                            && self::isPartsSumMismatch($get('job_ids') ?? [], $record))
                        ->required(fn (Get $get) => $get('kind') === BankMatchKind::PartPurchase->value
                            && self::isPartsSumMismatch($get('job_ids') ?? [], $record))
                        ->rows(2),

                    // ---- Tech payout: any same-day payout is offerable, exact or not ----
                    Select::make('tech_settlement_id')->label('End-of-Day payout')
                        ->visible(fn (Get $get) => $get('kind') === BankMatchKind::TechPayout->value)
                        ->required(fn (Get $get) => $get('kind') === BankMatchKind::TechPayout->value)
                        ->helperText('Payouts settled the same day the Zelle was initiated.')
                        ->options(fn () => self::payoutOptions($record, $tech))
                        ->live()
                        ->searchable(),
                    Textarea::make('payout_mismatch_note')
                        ->label("Payout doesn't equal the Zelle — explain the difference")
                        ->visible(fn (Get $get) => $get('kind') === BankMatchKind::TechPayout->value
                            && self::isPayoutMismatch($get('tech_settlement_id'), $record))
                        ->required(fn (Get $get) => $get('kind') === BankMatchKind::TechPayout->value
                            && self::isPayoutMismatch($get('tech_settlement_id'), $record))
                        ->rows(2),

                    Select::make('misc_category')->label('Category')
                        ->visible(fn (Get $get) => $get('kind') === BankMatchKind::Misc->value)
                        ->required(fn (Get $get) => $get('kind') === BankMatchKind::Misc->value)
                        ->options(BankMatchKind::miscCategories()),
                    Select::make('dividend_recipient')->label('Paid to (owner)')
                        ->visible(fn (Get $get) => $get('kind') === BankMatchKind::Dividend->value)
                        ->required(fn (Get $get) => $get('kind') === BankMatchKind::Dividend->value)
                        ->options(BankMatchKind::dividendRecipients()),
                    Textarea::make('note')->label('Note')->rows(2),
                ];
            })
            ->action(function (BankTransaction $record, array $data) {
                // A prior vendor-invoice link (suggested or confirmed) freed
                // its document(s) back to "open" before the human's new word
                // replaces it — otherwise a document could stay stuck
                // permanently claimed by a match that's about to be deleted.
                self::unstampVendorDocumentsCharged($record);

                // Replace whatever the matcher proposed — the human's word wins.
                $record->matches()->delete();

                $kind = BankMatchKind::from($data['kind']);

                match ($kind) {
                    BankMatchKind::JobPayment => self::saveJobPaymentMatch($record, $data),
                    BankMatchKind::PartPurchase => self::savePartPurchaseMatch($record, $data),
                    BankMatchKind::VendorInvoice => self::saveVendorInvoiceMatch($record, $data),
                    BankMatchKind::TechPayout => self::saveTechPayoutMatch($record, $data),
                    BankMatchKind::Misc, BankMatchKind::Dividend => self::saveSimpleMatch($record, $kind, $data),
                };

                Notification::make()->title('Transaction matched')->success()->send();
            });
    }

    /** Null charge_matched_at on any vendor document this transaction is currently linked to, before the link is replaced/removed. */
    private static function unstampVendorDocumentsCharged(BankTransaction $record): void
    {
        $ids = $record->matches()->where('kind', BankMatchKind::VendorInvoice->value)->whereNotNull('vendor_document_id')->pluck('vendor_document_id');

        if ($ids->isNotEmpty()) {
            VendorDocument::whereIn('id', $ids)->update(['charge_matched_at' => null]);
        }

        VendorPayment::where('bank_transaction_id', $record->id)->update(['bank_transaction_id' => null]);
    }

    // ---- Save helpers, one per match kind (all called from matchAction's action()) ----

    /** One match row per selected payment LINE; the sum-mismatch note (if any) rides on every row, matching the parts/legacy pattern. */
    private static function saveJobPaymentMatch(BankTransaction $record, array $data): void
    {
        $lineIds = $data['job_payment_ids'] ?? [];
        $lines = JobPayment::findMany($lineIds);
        $mismatched = self::isPaymentLineSumMismatch($lineIds, $record);
        $expected = $mismatched ? self::expectedPaymentLineSum($lineIds, $record) : null;
        $note = $data['payment_mismatch_note'] ?? null;

        foreach ($lines as $line) {
            $record->matches()->create([
                'kind' => BankMatchKind::JobPayment->value,
                'job_id' => $line->job_id,
                'job_payment_id' => $line->id,
                'source' => 'manual',
                'note' => $data['note'] ?? null,
                'expected_amount_cents' => $expected,
                'mismatch_note' => $mismatched ? $note : null,
            ]);
        }

        self::finishMatch($record, $mismatched, $expected, $note);
    }

    private static function savePartPurchaseMatch(BankTransaction $record, array $data): void
    {
        $jobIds = $data['job_ids'] ?? [];
        $mismatched = self::isPartsSumMismatch($jobIds, $record);
        $expected = $mismatched ? self::expectedPartsSum($jobIds) : null;
        $note = $data['parts_mismatch_note'] ?? null;

        foreach ($jobIds as $jobId) {
            $record->matches()->create([
                'kind' => BankMatchKind::PartPurchase->value,
                'job_id' => $jobId,
                'source' => 'manual',
                'note' => $data['note'] ?? null,
                'expected_amount_cents' => $expected,
                'mismatch_note' => $mismatched ? $note : null,
            ]);
        }

        self::finishMatch($record, $mismatched, $expected, $note);
    }

    /** One match row per selected document; a manual match is always committed (never left "suggested"), so charge_matched_at stamps immediately. */
    private static function saveVendorInvoiceMatch(BankTransaction $record, array $data): void
    {
        $docIds = $data['vendor_document_ids'] ?? [];
        $mismatched = self::isVendorDocumentSumMismatch($docIds, $record);
        $expected = $mismatched ? self::expectedVendorDocumentSum($docIds) : null;
        $note = $data['vendor_mismatch_note'] ?? null;

        foreach ($docIds as $docId) {
            $record->matches()->create([
                'kind' => BankMatchKind::VendorInvoice->value,
                'vendor_document_id' => $docId,
                'source' => 'manual',
                'note' => $data['note'] ?? null,
                'expected_amount_cents' => $expected,
                'mismatch_note' => $mismatched ? $note : null,
            ]);
        }

        VendorDocument::whereIn('id', $docIds)->update(['charge_matched_at' => now()]);
        VendorPayment::whereIn('vendor_document_id', $docIds)->whereNull('bank_transaction_id')->update(['bank_transaction_id' => $record->id]);

        self::finishMatch($record, $mismatched, $expected, $note);
    }

    private static function saveTechPayoutMatch(BankTransaction $record, array $data): void
    {
        $settlement = TechSettlement::find($data['tech_settlement_id'] ?? null);
        $mismatched = $settlement !== null && (int) $settlement->amount_cents !== (int) $record->amount_cents;
        $note = $data['payout_mismatch_note'] ?? null;

        $record->matches()->create([
            'kind' => BankMatchKind::TechPayout->value,
            'tech_settlement_id' => $settlement?->id,
            'source' => 'manual',
            'note' => $data['note'] ?? null,
            'expected_amount_cents' => $mismatched ? $settlement->amount_cents : null,
            'mismatch_note' => $mismatched ? $note : null,
        ]);

        self::finishMatch($record, $mismatched, $mismatched ? (int) $settlement->amount_cents : null, $note, $settlement?->technician?->name);
    }

    private static function saveSimpleMatch(BankTransaction $record, BankMatchKind $kind, array $data): void
    {
        $record->matches()->create([
            'kind' => $kind->value,
            // misc_category holds the misc category OR, for a dividend, the
            // owner-recipient key (a dividend has no misc category of its own).
            'misc_category' => match ($kind) {
                BankMatchKind::Misc => $data['misc_category'],
                BankMatchKind::Dividend => $data['dividend_recipient'],
                default => null,
            },
            'source' => 'manual',
            'note' => $data['note'] ?? null,
        ]);

        self::finishMatch($record, false, null, null);
    }

    /** Stamp the transaction matched, then raise (mismatch) or clear (clean match) the review Issue. */
    private static function finishMatch(BankTransaction $record, bool $mismatched, ?int $expectedCents, ?string $note, ?string $techName = null): void
    {
        $record->update([
            'match_status' => BankTransaction::STATUS_MATCHED,
            'matched_at' => now(),
            'matched_by' => self::actorId(),
        ]);

        if ($mismatched) {
            self::raiseMismatchIssue($record, (int) $expectedCents, $note, $techName);
        } else {
            // A fresh, clean match resolves whatever was off about this transaction.
            Issue::clearOpenFor($record, IssueType::TransactionMismatch);
        }
    }

    /**
     * Hang an open TransactionMismatch Issue on this transaction when a manual
     * match's expected amount doesn't equal the Chase amount. `details` uses the
     * exact keys Issues::mismatchLine reads (txn_description, bank_amount_cents,
     * expected_amount_cents, tech_name, note) so the super-admin queue renders it
     * without a special case. Issue::raise is idempotent — re-saving a still-
     * mismatched match just keeps the one open issue, never duplicates it.
     */
    private static function raiseMismatchIssue(BankTransaction $record, int $expectedCents, ?string $note, ?string $techName = null): void
    {
        Issue::raise(IssueType::TransactionMismatch, $record, [
            'txn_description' => $record->name,
            'bank_amount_cents' => abs($record->amount_cents),
            'expected_amount_cents' => $expectedCents,
            'tech_name' => $techName,
            'note' => $note,
        ], self::actorId());
    }

    /**
     * On-demand LLM assist: ask Claude to classify a transaction the
     * deterministic matcher left unresolved. Shown only when the assist is
     * enabled + configured (Admin → Integrations) and the row isn't matched.
     * Writes an AI-sourced *suggestion* the human then confirms/unmatches — it
     * never auto-confirms. Carries a small per-call API cost, hence on-demand.
     */
    private static function askAiAction(): Action
    {
        return Action::make('askAi')
            ->label('Ask AI')
            ->tooltip('Ask AI to suggest a match')
            ->iconButton()
            ->icon('heroicon-o-sparkles')
            ->color('info')
            ->visible(fn (BankTransaction $r) => $r->match_status !== BankTransaction::STATUS_MATCHED
                && app(LlmMatchSuggester::class)->enabled())
            ->action(function (BankTransaction $record) {
                $match = app(LlmMatchSuggester::class)->suggestFor($record);

                if ($match === null) {
                    Notification::make()
                        ->title('No confident AI suggestion')
                        ->body('The assistant could not confidently classify this one — match it by hand.')
                        ->warning()->send();

                    return;
                }

                Notification::make()
                    ->title('AI suggested: '.$match->describe())
                    ->body($match->note ? Str::after($match->note, 'AI: ') : null)
                    ->success()->send();
            });
    }

    private static function unmatchAction(): Action
    {
        return Action::make('unmatch')
            ->label('Unmatch')
            ->tooltip('Unmatch')
            ->iconButton()
            ->icon('heroicon-o-x-mark')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (BankTransaction $r) => $r->match_status !== BankTransaction::STATUS_UNMATCHED)
            ->action(function (BankTransaction $record) {
                self::unstampVendorDocumentsCharged($record);

                $record->matches()->delete();
                $record->update([
                    'match_status' => BankTransaction::STATUS_UNMATCHED,
                    'matched_at' => null,
                    'matched_by' => null,
                ]);

                Issue::clearOpenFor($record, IssueType::TransactionMismatch);

                Notification::make()->title('Match removed')->success()->send();
            });
    }

    // ---- Display helpers ----

    /** "PLAT BUS CHECKING" → Chk, "Ultimate Rewards®" → Crdt. */
    private static function shortAccount(?string $name): string
    {
        return match (true) {
            $name === null => '—',
            stripos($name, 'checking') !== false => 'Chk',
            stripos($name, 'sav') !== false => 'Sav',
            default => 'Crdt',
        };
    }

    /**
     * The "Matched to" badge text — "Matched" / "Suggested" / "Unmatched" — linked
     * to the matched job or that day's End-of-Day Summary (in a new window) when a
     * match exists. Falls back to plain text once multiple matches exist (a part
     * purchase can span several jobs) since there's no single target to link to.
     */
    /**
     * The "Matched to" badge's second line: what this transaction is actually
     * matched (or proposed) to, via BankTransactionMatch::describe().
     *
     * The badge itself can only link to a Job or a tech payout, so a legacy
     * payment, a misc category, a dividend, a vendor document — or any row
     * carrying SEVERAL matches (a parts charge split over jobs, a vendor charge
     * covering several documents) — has no link target and used to render as a
     * bare, unexplained word. Every kind describes itself here instead.
     */
    private static function matchSummary(BankTransaction $record): ?string
    {
        $matches = $record->matches;

        if ($matches->isEmpty()) {
            return null;
        }

        if ($matches->count() === 1) {
            return $matches->first()->describe();
        }

        // Several matches: name the first few rather than collapsing to a count,
        // since "which jobs" is the whole question a split charge raises.
        $shown = $matches->take(3)->map(fn (BankTransactionMatch $m) => $m->describe())->all();
        $extra = $matches->count() - count($shown);

        return implode(' + ', $shown).($extra > 0 ? ' + '.$extra.' more' : '');
    }

    private static function matchStatusHtml(string $status, BankTransaction $record): string
    {
        $label = match ($status) {
            BankTransaction::STATUS_MATCHED => 'Matched',
            BankTransaction::STATUS_SUGGESTED => 'Suggested',
            default => 'Unmatched',
        };

        $match = $record->matches->count() === 1 ? $record->matches->first() : null;

        $url = match (true) {
            $match === null => null,
            $match->job !== null => JobResource::getUrl('view', ['record' => $match->job]),
            $match->techSettlement?->for_date !== null => TechPayments::getUrl(['date' => $match->techSettlement->for_date]),
            default => null,
        };

        if ($url === null) {
            return e($label);
        }

        return '<a href="'.e($url).'" target="_blank" class="underline">'.e($label).'</a>';
    }

    // ---- Option builders: parts purchase (job_ids + warehouse) ----

    /**
     * Constrains a jobs query to the warehouse/date window
     * defaultPartJobOptions() uses — jobs whose glass was bought from the
     * selected warehouse (or, for "other", jobs on a free-text/dealer
     * supplier), ordered around when the part was ordered (falling back to
     * created_at for the rare job whose part was never marked ordered).
     * Shared with jobOptions() so a name search stays scoped to the same
     * window by default — same reasoning as the vendor-document picker's
     * applyVendorDocumentWindow().
     */
    private static function applyPartJobWindow(Builder $query, ?string $warehouseValue, BankTransaction $record): Builder
    {
        [$start, $end] = self::dayRange($record->matchDate(), 45, 3);

        $query->where(function (Builder $q) use ($start, $end) {
            $q->whereBetween('parts_ordered_at', [$start, $end])
                ->orWhere(function (Builder $inner) use ($start, $end) {
                    $inner->whereNull('parts_ordered_at')->whereBetween('created_at', [$start, $end]);
                });
        });

        if ($warehouseValue === 'other') {
            $query->whereNull('warehouse_id')->whereNotNull('warehouse_other');
        } elseif (filled($warehouseValue)) {
            $query->where('warehouse_id', $warehouseValue);
        }

        return $query;
    }

    /**
     * Window+warehouse candidates, OR'd with a job_number/customer-name search
     * that isn't constrained to that window/warehouse — same reasoning as the
     * vendor-document picker's search: some users will know the job number or
     * customer name and want to reach outside the default window; others are
     * just scrolling the default list, which must stay visible either way.
     */
    /**
     * ADDITIVELY unioned with any not-already-linked, priced job whose net
     * part cost (netPartCents()) is within tolerance of a dollar amount typed
     * into the same search box — see parseAmountSearch() for why a job-number
     * or customer-name search never accidentally triggers this branch.
     */
    private static function jobOptions(string $search, ?string $warehouseValue, BankTransaction $record): array
    {
        $jobs = Job::query()
            ->where(fn (Builder $q) => self::applyPartJobWindow($q, $warehouseValue, $record)
                ->orWhere(fn (Builder $q2) => $q2->where('job_number', 'like', "%{$search}%")
                    ->orWhere('customer_name_snapshot', 'like', "%{$search}%")))
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        $amountCents = self::parseAmountSearch($search);
        if ($amountCents !== null) {
            $tolerance = (int) config('glass.bank_matching.amount_tolerance_cents', 500);

            // Filtered/ranked against each job's REMAINING (not full net)
            // cost — a job already partly claimed by another charge must be
            // judged on what's actually still owed, same reasoning as
            // paymentLineContribution().
            $amountMatches = Job::query()
                ->where('part_cost_cents', '>', 0)
                ->whereNotIn('id', self::fullyClaimedPartJobIds($record))
                ->get()
                ->filter(fn (Job $j) => abs(self::remainingPartCentsForJob($j, $record) - $amountCents) <= $tolerance)
                ->sortBy(fn (Job $j) => abs(self::remainingPartCentsForJob($j, $record) - $amountCents));

            $jobs = $jobs->concat($amountMatches);
        }

        return $jobs->unique('id')->take(25)
            ->mapWithKeys(fn (Job $j) => [$j->id => self::partJobLabel($j, null, $record)])->all();
    }

    /**
     * Window+warehouse candidates — the DEFAULT list shown before the human
     * types a search — ranked by how close each job's net part cost is to the
     * charge amount, closest first. Ranking only, never exclusion: every job
     * the window/warehouse/part_cost_cents/not-already-linked filters allow
     * through is still offered, just reordered — a warehouse charge can
     * legitimately bundle several jobs' parts, so a hard amount cutoff could
     * hide a valid combination. Computed in PHP after fetch since it's a
     * derived expression over two columns (part_cost_cents,
     * tech_parts_cost_cents — see netPartCents()).
     *
     * ⚠️ 2026-07-30: same fix as the payment-line picker's
     * defaultPaymentLineOptions() — the "· Δ $X.XX" suffix only means
     * anything for a single candidate against the whole charge. Once 2+ jobs
     * are already selected, every label in this list drops the Δ entirely
     * rather than each still showing its own delta against the full charge.
     *
     * ⚠️ 2026-07-31: ranked and excluded on each job's REMAINING net part
     * cost, not the full amount — a job whose part cost was already partly
     * claimed by another charge (a $250 part billed across two Chase
     * charges) must rank/exclude on what's actually still owed. Same
     * sum-aware treatment as the payment-line picker; see
     * fullyClaimedPartJobIds()/remainingPartCentsForJob().
     */
    private static function defaultPartJobOptions(?string $warehouseValue, BankTransaction $record, array $alreadySelectedIds = []): array
    {
        $charge = abs($record->amount_cents);
        $chargeForLabel = count($alreadySelectedIds) >= 2 ? null : $charge;

        $query = self::applyPartJobWindow(
            Job::query()->where('part_cost_cents', '>', 0)->whereNotIn('id', self::fullyClaimedPartJobIds($record)),
            $warehouseValue,
            $record,
        );

        return $query->get()
            ->sortBy(fn (Job $j) => abs(self::remainingPartCentsForJob($j, $record) - $charge))
            ->take(25)
            ->values()
            ->mapWithKeys(fn (Job $j) => [$j->id => self::partJobLabel($j, $chargeForLabel, $record)])
            ->all();
    }

    /**
     * Job ids whose existing part_purchase matches already sum to at least
     * the job's own NET part cost (part_cost_cents minus tech-paid) — no room
     * left. Mirrors BankMatcher::fullyClaimedLineIds() exactly, expressing
     * netPartCents() as the same SQL the auto-matcher's findPartPurchase()
     * already uses. $excluding — see fullyClaimedSettlementIds().
     */
    private static function fullyClaimedPartJobIds(?BankTransaction $excluding = null): Collection
    {
        return BankTransactionMatch::query()
            ->join('bank_transactions', 'bank_transactions.id', '=', 'bank_transaction_matches.bank_transaction_id')
            ->join('jobs', 'jobs.id', '=', 'bank_transaction_matches.job_id')
            ->where('bank_transaction_matches.kind', BankMatchKind::PartPurchase->value)
            ->whereNotNull('bank_transaction_matches.job_id')
            ->when($excluding, fn (Builder $q) => $q->where('bank_transaction_matches.bank_transaction_id', '!=', $excluding->id))
            ->groupBy('bank_transaction_matches.job_id', 'jobs.part_cost_cents', 'jobs.tech_parts_cost_cents')
            ->havingRaw('sum(abs(bank_transactions.amount_cents)) >= (jobs.part_cost_cents - coalesce(jobs.tech_parts_cost_cents, 0))')
            ->pluck('bank_transaction_matches.job_id');
    }

    /** Cents of a job's NET part cost not yet claimed by any existing part_purchase match. Never negative. */
    private static function remainingPartCentsForJob(Job $job, ?BankTransaction $excluding = null): int
    {
        $claimed = (int) BankTransactionMatch::query()
            ->join('bank_transactions', 'bank_transactions.id', '=', 'bank_transaction_matches.bank_transaction_id')
            ->where('bank_transaction_matches.kind', BankMatchKind::PartPurchase->value)
            ->where('bank_transaction_matches.job_id', $job->id)
            ->when($excluding, fn (Builder $q) => $q->where('bank_transaction_matches.bank_transaction_id', '!=', $excluding->id))
            ->selectRaw('coalesce(sum(abs(bank_transactions.amount_cents)), 0) as claimed')
            ->value('claimed');

        return max(0, self::netPartCents($job) - $claimed);
    }

    /**
     * "J-1234 — John Smith — part $250.00", plus "(− tech-paid $100.00 =
     * $150.00)" when the tech fronted some of the part — the tech-paid slice
     * never shows up at Chase (it's reimbursed through tech pay), so the
     * picker's own label spells out the net a human should expect to match.
     * Plus "$X.XX left to match" when another charge already claimed part of
     * this job's net cost (see remainingPartCentsForJob()) — same trailing
     * footnote as paymentLineLabel(). $chargeCents (only passed from the
     * amount-ranked default list, never search or already-selected chips)
     * appends "· Δ $X.XX" against the REMAINING net cost — the same figure
     * the default list is now ranked by — not the full net cost, or Δ would
     * misstate the actual gap once a job is partially claimed.
     */
    private static function partJobLabel(Job $job, ?int $chargeCents = null, ?BankTransaction $excluding = null): string
    {
        $bits = array_filter([
            $job->job_number,
            $job->customer_name_snapshot,
            'part '.Money::format((int) $job->part_cost_cents),
        ]);

        $label = implode(' — ', $bits);

        $techPaid = (int) ($job->tech_parts_cost_cents ?? 0);
        if ($techPaid > 0) {
            $label .= ' (− tech-paid '.Money::format($techPaid).' = '.Money::format(self::netPartCents($job)).')';
        }

        $remaining = self::remainingPartCentsForJob($job, $excluding);
        if ($remaining < self::netPartCents($job)) {
            $label .= ' · '.Money::format($remaining).' left to match';
        }

        if ($chargeCents !== null) {
            $label .= ' · Δ '.Money::format(abs($remaining - $chargeCents));
        }

        return $label;
    }

    /** What a job's part SHOULD show up as at the bank: total part cost minus whatever the tech already fronted. */
    private static function netPartCents(Job $job): int
    {
        return max(0, (int) $job->part_cost_cents - (int) ($job->tech_parts_cost_cents ?? 0));
    }

    /** All Warehouse rows by name, plus a catch-all "Other supplier" option (jobs with no warehouse_id, free-text warehouse_other). */
    private static function warehouseSelectOptions(): array
    {
        return Warehouse::query()->orderBy('name')->pluck('name', 'id')
            ->put('other', 'Other supplier')
            ->all();
    }

    /**
     * Best guess at which warehouse a parts-vendor charge belongs to, from the
     * transaction's own description: a warehouse's name appearing verbatim wins
     * outright; otherwise a configured vendor keyword (glass.bank_matching.
     * parts_vendors) found in the description that fuzzy-maps to exactly one
     * warehouse (case-insensitive substring either direction — e.g. "mygrant"
     * found in the description and contained in a warehouse named "Mygrant").
     * Anything ambiguous or with no hit is left null for the human to pick.
     */
    private static function resolveWarehouseFromDescription(BankTransaction $record): ?string
    {
        $description = mb_strtolower($record->name.' '.($record->merchant_name ?? ''));
        $warehouses = Warehouse::query()->pluck('name', 'id');

        $direct = $warehouses->filter(fn (string $name) => mb_strlen(trim($name)) >= 3
            && str_contains($description, mb_strtolower(trim($name))));
        if ($direct->count() === 1) {
            return (string) $direct->keys()->first();
        }

        foreach ((array) config('glass.bank_matching.parts_vendors', []) as $vendor) {
            $needle = mb_strtolower($vendor);
            if ($needle === '' || ! str_contains($description, $needle)) {
                continue;
            }

            $matches = $warehouses->filter(fn (string $name) => str_contains(mb_strtolower($name), $needle)
                || str_contains($needle, mb_strtolower($name)));
            if ($matches->count() === 1) {
                return (string) $matches->keys()->first();
            }
        }

        return null;
    }

    /**
     * Live tally under "Job(s) the parts were for": the charge total up top,
     * one line per selected job at its NET expected amount (part cost minus
     * whatever the tech already fronted), then the running total and what's
     * left to account for.
     */
    private static function partsTallyHelperText(array $jobIds, BankTransaction $record): HtmlString
    {
        $charge = abs($record->amount_cents);
        $head = '<strong>Charge: '.Money::format($charge).'</strong>';

        if ($jobIds === []) {
            return new HtmlString($head.' — add every job this charge covers.');
        }

        $jobs = Job::findMany($jobIds);

        $lines = $jobs->map(fn (Job $j) => e($j->job_number.($j->customer_name_snapshot ? ' ('.$j->customer_name_snapshot.')' : ''))
            .' — '.Money::format(self::netPartCents($j)));

        $selected = (int) $jobs->sum(fn (Job $j) => self::netPartCents($j));
        $diff = $charge - $selected;

        $tally = match (true) {
            $diff === 0 => '<strong>Total: '.Money::format($selected).' ✓ adds up</strong>',
            $diff > 0 => '<strong>Total: '.Money::format($selected).'</strong> — '.Money::format($diff).' left to account for',
            default => '<strong>Total: '.Money::format($selected).'</strong> — '.Money::format(abs($diff)).' over the charge',
        };

        return new HtmlString($head.'<br>'.$lines->join('<br>').'<br>'.$tally);
    }

    private static function expectedPartsSum(array $jobIds): int
    {
        return (int) Job::findMany($jobIds)->sum(fn (Job $j) => self::netPartCents($j));
    }

    /** True when the selected jobs' net expected total doesn't equal the charge. */
    private static function isPartsSumMismatch(array $jobIds, BankTransaction $record): bool
    {
        return $jobIds !== [] && self::expectedPartsSum($jobIds) !== abs($record->amount_cents);
    }

    // ---- Option builders: vendor invoice (vendor_document_ids) ----

    /**
     * Constrains a vendor_documents query to the charge's own vendor group
     * (App\Enums\PartsSupplier — already the "vendor group" a multi-warehouse
     * supplier like Mygrant rolls up under; no per-warehouse string matching
     * involved) and to documents settling within 10 days either side of the
     * charge's matchDate(). $supplier is whatever matchAction() already
     * resolved via VendorChargeReconciler::isMygrantCharge() /
     * IgcChargeReconciler::isIgcCharge() — null when the charge wasn't
     * recognized as either (a human picked "Vendor invoice" manually), in which
     * case we fall back to date-only so the field never dead-ends into nothing.
     */
    private static function applyVendorDocumentWindow(Builder $query, BankTransaction $record, ?string $supplier): Builder
    {
        $matchDate = Carbon::parse($record->matchDate());

        return $query
            ->when($supplier !== null, fn (Builder $q) => $q->where('supplier', $supplier))
            ->whereBetween('settle_date', [
                $matchDate->copy()->subDays(10)->toDateString(),
                $matchDate->copy()->addDays(10)->toDateString(),
            ]);
    }

    /** Vendor+date-window candidates — the default list before a human searches. */
    private static function defaultVendorDocumentOptions(BankTransaction $record, ?string $supplier): array
    {
        return self::applyVendorDocumentWindow(VendorDocument::query()->whereNull('charge_matched_at'), $record, $supplier)
            ->orderByDesc('settle_date')
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (VendorDocument $d) => [$d->id => self::vendorDocumentLabel($d)])
            ->all();
    }

    /**
     * Vendor+date-window candidates, OR'd with a document_number search that
     * isn't constrained to that window/vendor — some users will know the
     * number and want to reach outside the default window (e.g. correcting a
     * stale link) even if it's the wrong supplier or settled long ago; others
     * are just scrolling the default list, which must stay visible either way.
     * charge_matched_at IS NULL still applies unconditionally either way, so an
     * already-matched document is never offered as a candidate.
     *
     * ADDITIVELY unioned with any unmatched document whose effectiveAmountCents()
     * is within tolerance of a dollar amount typed into the same search box —
     * see parseAmountSearch() for why a document/job-number search never
     * accidentally triggers this branch.
     */
    private static function searchVendorDocumentOptions(string $search, BankTransaction $record, ?string $supplier): array
    {
        $documents = VendorDocument::query()
            ->whereNull('charge_matched_at')
            ->where(fn (Builder $q) => self::applyVendorDocumentWindow($q, $record, $supplier)
                ->orWhere('document_number', 'like', "%{$search}%"))
            ->orderByDesc('settle_date')
            ->limit(25)
            ->get();

        $amountCents = self::parseAmountSearch($search);
        if ($amountCents !== null) {
            $tolerance = (int) config('glass.bank_matching.amount_tolerance_cents', 500);

            $amountMatches = VendorDocument::query()
                ->whereNull('charge_matched_at')
                ->when($supplier !== null, fn (Builder $q) => $q->where('supplier', $supplier))
                ->get()
                ->filter(fn (VendorDocument $d) => abs(abs($d->effectiveAmountCents()) - $amountCents) <= $tolerance)
                ->sortBy(fn (VendorDocument $d) => abs(abs($d->effectiveAmountCents()) - $amountCents));

            $documents = $documents->concat($amountMatches);
        }

        return $documents->unique('id')->take(25)
            ->mapWithKeys(fn (VendorDocument $d) => [$d->id => self::vendorDocumentLabel($d)])->all();
    }

    /** "IGC Invoice 1924343 — $448.00 (settles 7/24/26)" — supplier-prefixed now that both Mygrant and IGC documents share this picker. */
    private static function vendorDocumentLabel(VendorDocument $document): string
    {
        $prefix = match ($document->supplier) {
            PartsSupplier::Mygrant->value => 'Mygrant ',
            PartsSupplier::Igc->value => 'IGC ',
            default => '',
        };

        return $prefix.$document->documentLabel().' — '.Money::format($document->effectiveAmountCents()).' (settles '.$document->settle_date.')';
    }

    /**
     * Live tally under "Document(s) this charge covers": the charge total up
     * top, one line per selected document, then the running total and what's
     * left — same shape as the parts-purchase tally.
     */
    private static function vendorDocumentTallyHelperText(array $docIds, BankTransaction $record): HtmlString
    {
        $charge = abs($record->amount_cents);
        $head = '<strong>Charge: '.Money::format($charge).'</strong>';

        if ($docIds === []) {
            return new HtmlString($head.' — add every document this charge covers.');
        }

        $documents = VendorDocument::findMany($docIds);

        $lines = $documents->map(fn (VendorDocument $d) => e($d->documentLabel()).' — '.Money::format($d->effectiveAmountCents()));

        $selected = self::expectedVendorDocumentSum($docIds);
        $diff = $charge - $selected;

        $tally = match (true) {
            $diff === 0 => '<strong>Total: '.Money::format($selected).' ✓ adds up</strong>',
            $diff > 0 => '<strong>Total: '.Money::format($selected).'</strong> — '.Money::format($diff).' left to account for',
            default => '<strong>Total: '.Money::format($selected).'</strong> — '.Money::format(abs($diff)).' over the charge',
        };

        return new HtmlString($head.'<br>'.$lines->join('<br>').'<br>'.$tally);
    }

    private static function expectedVendorDocumentSum(array $docIds): int
    {
        return (int) VendorDocument::findMany($docIds)->sum(fn (VendorDocument $d) => $d->effectiveAmountCents());
    }

    /** True when the selected documents' total doesn't equal the charge. */
    private static function isVendorDocumentSumMismatch(array $docIds, BankTransaction $record): bool
    {
        return $docIds !== [] && self::expectedVendorDocumentSum($docIds) !== abs($record->amount_cents);
    }

    // ---- Option builders: customer payment (job_payment_ids) ----

    /**
     * Payment lines shown before the human searches: unmatched, bank-matchable
     * (Stripe-only for a Stripe payout), within a single -7/+1 day window
     * around the deposit's date, ranked by proximity between each line's
     * EXPECTED bank amount (net-of-Stripe-fee for a Stripe deposit, raw
     * amount otherwise) and the deposit — closest first, same shape as the
     * parts-purchase job picker's amount ranking.
     *
     * ⚠️ Replaced a same-Pacific-day-first, fall-back-to-±3-days two-tier
     * query that shipped before any real Stripe payout data existed. Found
     * broken against real production deposit 947 (2026-07-29, $4,760.04):
     * it found one same-day candidate and, because that check alone is
     * `->isNotEmpty()`, NEVER EVEN RAN the ±3-day fallback query — hiding 6
     * more genuinely unmatched candidates. Worse, even the fallback's ±3
     * days wouldn't have caught them: the real payout bundled charges
     * collected 2026-07-15 through 2026-07-22, a 7-day spread. -7/+1 is the
     * window that actually surfaced all 7 real candidates for that deposit;
     * kept generous on purpose since Stripe's payout-bundling window isn't
     * fully characterized yet (see StripePayoutReconciler's own doc comment
     * on the still-thin real-lag sample).
     *
     * Ranking only — no candidate is excluded for being far in amount. A
     * Stripe payout can bundle several charges (that deposit's own 7
     * candidates summed $438.37 short of it, meaning at least one more
     * candidate sits outside even this window) — the picker's job is to
     * show every real candidate and let a human pick the combination, never
     * to auto-find one. That's deliberately left to the paused stripe_payouts/
     * Payouts API work (StripePayoutReconciler), which resolves the exact
     * combination authoritatively once a real payout.paid webhook fires,
     * instead of guessing from date+amount proximity the way this picker
     * necessarily still does today.
     *
     * ⚠️ 2026-07-30: the "· Δ $X.XX" label suffix is only meaningful when
     * comparing ONE line against the whole deposit — with 2+ lines already
     * selected, each one showing its OWN delta against the FULL deposit is
     * nonsense (two lines on an $851.12 deposit separately showing
     * Δ $300.30 and Δ $516.63, neither of which is the real $34.19
     * shortfall). $alreadySelectedIds lets this react to the live selection:
     * once 2+ lines are already chosen, every label in this list — selected
     * or still a candidate — drops the per-line Δ entirely. The group's real
     * shortfall is already the aggregate depositTallyHelperText() shows
     * below the field ("$X left to account for"); this isn't trying to
     * duplicate that per line.
     */
    private static function defaultPaymentLineOptions(BankTransaction $record, array $alreadySelectedIds = []): array
    {
        $stripe = self::isStripeDeposit($record);
        $methods = $stripe ? [PaymentMethod::Stripe->value] : BankMatcher::bankMatchableMethods();
        $deposit = abs($record->amount_cents);
        $matcher = app(BankMatcher::class);
        $depositForLabel = count($alreadySelectedIds) >= 2 ? null : $deposit;

        // $record excluded from its own tally — reopening Match on an
        // already-suggested transaction is about to REPLACE that claim
        // (matchAction's action() deletes it first), not add to it.
        $lines = $matcher->unmatchedPaymentLines($record)
            ->whereIn('method', $methods)
            ->whereBetween('collected_at', self::dayRange($record->matchDate(), 7, 1))
            ->with('job')
            ->get();

        return $lines
            ->sortBy(fn (JobPayment $l) => abs(($stripe ? self::expectedNetCentsForAmount((int) $l->amount_cents) : $matcher->remainingCentsForLine($l, $record)) - $deposit))
            ->take(25)
            ->values()
            ->mapWithKeys(fn (JobPayment $l) => [$l->id => $stripe ? self::cardLineLabel($l, $depositForLabel) : self::paymentLineLabel($l, $depositForLabel, $record)])
            ->all();
    }

    /**
     * Search beyond the default window: any line with room left whose job
     * number/customer name hits, ADDITIVELY unioned with any line whose
     * amount (expected net for Stripe, remaining balance otherwise) is
     * within tolerance of a dollar amount typed into the same box — the
     * legacy-import case has no customer name a dispatcher would recognize,
     * so the amount is often the only thing they actually know to search by.
     * Never replaces the text search; a search that isn't shaped like a
     * dollar amount (a job number, a name) only ever runs the text branch.
     */
    private static function searchPaymentLineOptions(string $search, BankTransaction $record): array
    {
        $methods = self::isStripeDeposit($record) ? [PaymentMethod::Stripe->value] : BankMatcher::bankMatchableMethods();
        $stripe = self::isStripeDeposit($record);
        $matcher = app(BankMatcher::class);

        $lines = $matcher->unmatchedPaymentLines($record)
            ->whereIn('method', $methods)
            ->whereHas('job', fn (Builder $q) => $q
                ->where('job_number', 'like', "%{$search}%")
                ->orWhere('customer_name_snapshot', 'like', "%{$search}%"))
            ->with('job')
            ->orderByDesc('collected_at')
            ->limit(25)
            ->get();

        $amountCents = self::parseAmountSearch($search);
        if ($amountCents !== null) {
            $tolerance = (int) config('glass.bank_matching.amount_tolerance_cents', 500);
            $amountOf = fn (JobPayment $l) => $stripe
                ? self::expectedNetCentsForAmount((int) $l->amount_cents)
                : $matcher->remainingCentsForLine($l, $record);

            // Unlike the text branch above (an indexed, LIMIT-ed SQL query),
            // this pulls every candidate into PHP to compute a derived amount
            // before it can filter — a performance backstop, not a business
            // rule, so it's generous rather than tight. See the config key's
            // own comment for why only this branch needs one.
            //
            // Anchored on the TRANSACTION's own date (dayRange, same as every
            // other window in this file), never on wall-clock now() — a bank
            // transaction that's sat unmatched for a while must still be able
            // to reach payment lines from around ITS OWN date, not "the last
            // year as of today".
            $lookbackDays = (int) config('glass.bank_matching.search_lookback_days', 365);
            [$earliestCollectedAt] = self::dayRange($record->matchDate(), $lookbackDays, 0);

            $amountMatches = $matcher->unmatchedPaymentLines($record)
                ->whereIn('method', $methods)
                ->where('collected_at', '>=', $earliestCollectedAt)
                ->with('job')
                ->get()
                ->filter(fn (JobPayment $l) => abs($amountOf($l) - $amountCents) <= $tolerance)
                ->sortBy(fn (JobPayment $l) => abs($amountOf($l) - $amountCents));

            $lines = $lines->concat($amountMatches);
        }

        return $lines->unique('id')->take(25)
            ->mapWithKeys(fn (JobPayment $l) => [$l->id => $stripe ? self::cardLineLabel($l) : self::paymentLineLabel($l, null, $record)])->all();
    }

    /**
     * Parses a Match-modal search box's text as a dollar amount, or null when
     * it isn't shaped like one. Deliberately strict (digits, optional
     * leading $, optional comma groups, optional cents) so a job number
     * ("LA-2026-0330") or a name never accidentally fires the amount branch
     * — those contain letters/dashes this pattern rejects outright. Requires
     * at least $1 so a bare "1" doesn't pull in every line under tolerance.
     */
    private static function parseAmountSearch(string $search): ?int
    {
        $trimmed = trim($search);
        if (! preg_match('/^\$?\d{1,3}(,\d{3})*(\.\d{1,2})?$/', $trimmed)) {
            return null;
        }

        $cents = Money::centsFromDecimalString(ltrim($trimmed, '$'));

        return $cents >= 100 ? $cents : null;
    }

    /**
     * "J-1234 — John Smith · Zelle — $150.00 — Jul 22", plus "(payment 1 of 2)"
     * when the job has more than one line, plus "$X.XX left to match" when
     * the line is already partially claimed by another bank transaction (see
     * BankMatcher::remainingCentsForLine()) — otherwise a $798 line with
     * $399 already matched elsewhere would read as if the whole $798 were
     * still up for grabs. $depositCents (only passed from the amount-ranked
     * default list, never search or already-selected chips) appends
     * "· Δ $X.XX" against the REMAINING balance — the same figure the list
     * is now sorted by, spelled out so the ranking isn't a silent ordering.
     * $excluding is the transaction whose own Match modal this is — see
     * BankMatcher::unmatchedPaymentLines() for why it's excluded from the
     * "already claimed" tally.
     *
     * The method sits right next to the customer name — not buried in the
     * amount bit — so a human can eyeball "does this method make sense for
     * this deposit" without opening anything else. It's set here, in the
     * name bit, BEFORE the Δ-suppression logic below runs, so it shows
     * whether or not Δ does.
     *
     * The trailing bare date is when the PAYMENT was collected
     * (JobPayment::collected_at). A second, "done"-prefixed date is the job's
     * OWN completion (Job::work_completed_at — when the tech finished, not
     * Job::completed_at, which also requires full payment + tech pay and so
     * is often still null for a job that's only just had a payment line
     * recorded). Prefixed specifically so two bare dates in one label are
     * never ambiguous about which is which.
     */
    private static function paymentLineLabel(JobPayment $line, ?int $depositCents = null, ?BankTransaction $excluding = null): string
    {
        $job = $line->job;
        $nameBit = implode(' · ', array_filter([$job?->customer_name_snapshot, $line->method?->label()]));

        $bits = array_filter([
            $job?->job_number,
            $nameBit,
            Money::format((int) $line->amount_cents),
            optional($line->collected_at)->format('M j'),
            self::completedDateBit($job),
        ]);

        $label = implode(' — ', $bits);

        $siblings = $job?->payments; // Job::payments() is ordered by collected_at, id.
        if ($siblings !== null && $siblings->count() > 1) {
            $position = $siblings->search(fn (JobPayment $p) => $p->id === $line->id);
            if ($position !== false) {
                $label .= ' (payment '.($position + 1)." of {$siblings->count()})";
            }
        }

        $remaining = app(BankMatcher::class)->remainingCentsForLine($line, $excluding);
        if ($remaining < (int) $line->amount_cents) {
            $label .= ' · '.Money::format($remaining).' left to match';
        }

        if ($depositCents !== null) {
            $label .= ' · Δ '.Money::format(abs($remaining - $depositCents));
        }

        return $label;
    }

    /**
     * Card-paid line option: what was billed on the card, and the expected
     * net after Stripe's cut. $depositCents (see paymentLineLabel()) appends
     * the delta between the EXPECTED NET (not the billed amount) and the
     * deposit — that's the figure the list is actually ranked by.
     *
     * Carries "· Stripe/CC" next to the name, same as paymentLineLabel(),
     * for consistency across the one "Payment line(s) this deposit covers"
     * field these two functions share (which one renders depends on
     * isStripeDeposit()). It's always Stripe/CC here — this branch only ever
     * offers Stripe-method lines — so it reads as confirmation, not a choice.
     */
    private static function cardLineLabel(JobPayment $line, ?int $depositCents = null): string
    {
        $billed = (int) $line->amount_cents;
        $net = self::expectedNetCentsForAmount($billed);
        $job = $line->job;
        $nameBit = implode(' · ', array_filter([$job?->customer_name_snapshot, $line->method?->label()]));

        $label = implode(' — ', array_filter([
            $job?->job_number,
            $nameBit,
            'billed '.Money::format($billed).' · net '.Money::format($net),
        ]));

        $completed = self::completedDateBit($job);
        if ($completed !== null) {
            $label .= ' · '.$completed;
        }

        if ($depositCents !== null) {
            $label .= ' · Δ '.Money::format(abs($net - $depositCents));
        }

        return $label;
    }

    /**
     * "done Jul 22" (work_completed_at, converted to Pacific before display —
     * same convention as payoutLabel()'s settled_at handling), or null when
     * the job has no completion stamp / is deleted / is labor-only or
     * otherwise never reached it. Shared by both label builders above.
     */
    private static function completedDateBit(?Job $job): ?string
    {
        if ($job?->work_completed_at === null) {
            return null;
        }

        $local = $job->work_completed_at->copy()->timezone(config('glass.timezone'));

        return 'done '.$local->format('M j');
    }

    /**
     * What Stripe deposits for one charge. $revenueCents is the JOB's revenue
     * (JobPayment::amount_cents — the quote balance, per
     * JobLifecycle::recordStripeWebhookPayment's own doc comment: "not the
     * card amount_total"). The customer's card was actually charged
     * revenue x (1 + surcharge%) (see JobLifecycle::createAndTextStripeInvoice),
     * and Stripe's own 2.9% + 30c fee is taken off THAT gross-with-surcharge
     * amount, not off the revenue figure directly — computing the fee against
     * revenue alone (the previous version of this method) understated the true
     * net by roughly the surcharge percentage of every line, e.g. ~$14.56 low
     * on a $500 job. See ExpectedNetCentsForAmountTest for the worked example.
     */
    private static function expectedNetCentsForAmount(int $revenueCents): int
    {
        $surchargePct = (float) config('glass.stripe.surcharge_percent', 3);
        $grossChargedCents = (int) round($revenueCents * (1 + $surchargePct / 100));

        $pct = (float) config('glass.stripe.fee_percent', 2.9);
        $fixed = (int) config('glass.stripe.fee_fixed_cents', 30);

        return $grossChargedCents - ((int) round($grossChargedCents * $pct / 100) + $fixed);
    }

    /**
     * Live tally under "Payment line(s) this deposit covers": the deposit total
     * up top, then one line per selected payment line, then the running total
     * and what's still left to account for — so a Stripe payout that bundles
     * several customers' card payments can be reconciled as lines are added.
     * For a Stripe deposit each line shows billed → expected net (billed minus
     * Stripe's 2.9% + 30¢), and the tally compares expected net against the
     * deposit.
     */
    private static function depositTallyHelperText(array $lineIds, BankTransaction $record): HtmlString
    {
        $stripe = self::isStripeDeposit($record);
        $deposit = abs($record->amount_cents);
        $head = '<strong>Deposit: '.Money::format($deposit).'</strong>';

        if ($lineIds === []) {
            return new HtmlString($head.($stripe
                ? ' — a Stripe payout bundles card payments net of Stripe\'s fee; add every payment line it covers.'
                : ' — add every payment line this deposit covers.'));
        }

        $lines = JobPayment::with('job')->findMany($lineIds);

        $rows = $lines->map(function (JobPayment $line) use ($stripe, $record) {
            $job = $line->job;
            $who = e(($job?->job_number ?? 'J?').($job?->customer_name_snapshot ? ' ('.$job->customer_name_snapshot.')' : ''));
            $billed = (int) $line->amount_cents;

            if ($stripe) {
                return $who.' — billed '.Money::format($billed).' → net '.Money::format(self::expectedNetCentsForAmount($billed));
            }

            // Show the REMAINING balance, not the full line amount, when the
            // line is already partially claimed by another bank transaction —
            // otherwise this row would silently disagree with the tally below
            // (which already compares against the remaining balance) and with
            // the picker's own "$X.XX left to match" label.
            $remaining = app(BankMatcher::class)->remainingCentsForLine($line, $record);

            return $remaining < $billed
                ? $who.' — '.Money::format($remaining).' remaining (of '.Money::format($billed).')'
                : $who.' — '.Money::format($billed);
        });

        $selected = (int) $lines->sum(fn (JobPayment $line) => self::paymentLineContribution($line, $record));
        $diff = $deposit - $selected;
        $label = $stripe ? 'Expected net' : 'Total';

        $tally = match (true) {
            $diff === 0 => "<strong>{$label}: ".Money::format($selected).' ✓ adds up</strong>',
            $diff > 0 => "<strong>{$label}: ".Money::format($selected).'</strong> — '.Money::format($diff).' left to account for',
            default => "<strong>{$label}: ".Money::format($selected).'</strong> — '.Money::format(abs($diff)).' over the deposit',
        };

        if ($stripe) {
            $billedTotal = (int) $lines->sum('amount_cents');
            $tally .= '<br>Billed to customers: '.Money::format($billedTotal)
                .' · Stripe\'s fee: '.Money::format($billedTotal - $selected);
        }

        return new HtmlString($head.'<br>'.$rows->join('<br>').'<br>'.$tally);
    }

    /** What the selected payment lines should show up as at the bank, given how the deposit arrived. */
    private static function expectedPaymentLineSum(array $lineIds, BankTransaction $record): int
    {
        $lines = JobPayment::findMany($lineIds);

        return (int) $lines->sum(fn (JobPayment $l) => self::paymentLineContribution($l, $record));
    }

    /**
     * A single payment line's contribution to a deposit tally: expected
     * net-of-Stripe-fee for a card deposit (fees are charged against the
     * FULL original card charge, not a remaining balance — a card line
     * doesn't split across multiple Chase deposits the way a Zelle can), or
     * the line's own REMAINING unclaimed balance otherwise (see
     * BankMatcher::remainingCentsForLine() — 0 for an already-fully-matched
     * line, the full amount for a never-touched one). $record is excluded
     * from the "already claimed" tally — see unmatchedPaymentLines().
     */
    private static function paymentLineContribution(JobPayment $line, BankTransaction $record): int
    {
        return self::isStripeDeposit($record)
            ? self::expectedNetCentsForAmount((int) $line->amount_cents)
            : app(BankMatcher::class)->remainingCentsForLine($line, $record);
    }

    /** True when the selected payment lines' expected bank total doesn't equal the deposit. */
    private static function isPaymentLineSumMismatch(array $lineIds, BankTransaction $record): bool
    {
        return $lineIds !== [] && self::expectedPaymentLineSum($lineIds, $record) !== abs($record->amount_cents);
    }

    /** An incoming deposit from a Stripe payout, by description. */
    private static function isStripeDeposit(BankTransaction $record): bool
    {
        return $record->amount_cents < 0
            && stripos($record->name.' '.($record->merchant_name ?? ''), 'stripe') !== false;
    }

    // ---- Option builders: tech payout (tech_settlement_id) ----

    /**
     * The End-of-Day (or warranty) payouts this bank transaction can match: any
     * settled the SAME (Pacific) day as the transaction, not undone, not YET
     * fully claimed — the amount no longer has to match exactly (a human
     * explains a mismatch instead). A settlement paid out across two Zelles
     * (a $370 payout sent as $180 + $190) stays offerable after the first is
     * matched, for the remaining $180 — see fullyClaimedSettlementIds(),
     * same sum-aware treatment as unmatchedPaymentLines() for payment lines.
     * When $tech is given, that tech's own payouts are listed first — the
     * common case where the ledger already knows who the Zelle was to.
     */
    private static function payoutOptions(BankTransaction $record, ?Employee $tech = null): array
    {
        [$start, $end] = self::sameDayRange($record->matchDate());

        $settlements = TechSettlement::query()
            ->whereIn('type', ['eod_payout', 'warranty_payout'])
            ->where('amount_cents', '>', 0)
            ->whereNull('undone_at')
            ->whereBetween('settled_at', [$start, $end])
            ->whereNotIn('id', self::fullyClaimedSettlementIds($record))
            ->with('technician')
            ->orderByDesc('settled_at')
            ->get();

        if ($tech) {
            [$mine, $others] = $settlements->partition(fn (TechSettlement $s) => $s->technician_id === $tech->id);
            $settlements = $mine->concat($others);
        }

        return $settlements->mapWithKeys(fn (TechSettlement $s) => [$s->id => self::payoutLabel($s, $record)])->all();
    }

    /**
     * TechSettlement ids whose existing tech_payout matches already sum to at
     * least the settlement's own amount — no room left. $excluding is the
     * transaction whose own Match modal this is, same reasoning as
     * BankMatcher::unmatchedPaymentLines(): reopening Match on an
     * already-suggested transaction is about to REPLACE that claim, not add
     * to it, so it must not count against itself.
     */
    private static function fullyClaimedSettlementIds(?BankTransaction $excluding = null): Collection
    {
        return BankTransactionMatch::query()
            ->join('bank_transactions', 'bank_transactions.id', '=', 'bank_transaction_matches.bank_transaction_id')
            ->join('tech_settlements', 'tech_settlements.id', '=', 'bank_transaction_matches.tech_settlement_id')
            ->where('bank_transaction_matches.kind', BankMatchKind::TechPayout->value)
            ->whereNotNull('bank_transaction_matches.tech_settlement_id')
            ->when($excluding, fn (Builder $q) => $q->where('bank_transaction_matches.bank_transaction_id', '!=', $excluding->id))
            ->groupBy('bank_transaction_matches.tech_settlement_id', 'tech_settlements.amount_cents')
            ->havingRaw('sum(abs(bank_transactions.amount_cents)) >= tech_settlements.amount_cents')
            ->pluck('bank_transaction_matches.tech_settlement_id');
    }

    /**
     * Cents of a settlement's own amount not yet claimed by any existing
     * tech_payout match. Never negative. Mirrors
     * BankMatcher::remainingCentsForLine() exactly.
     */
    private static function remainingCentsForSettlement(TechSettlement $settlement, ?BankTransaction $excluding = null): int
    {
        $claimed = (int) BankTransactionMatch::query()
            ->join('bank_transactions', 'bank_transactions.id', '=', 'bank_transaction_matches.bank_transaction_id')
            ->where('bank_transaction_matches.kind', BankMatchKind::TechPayout->value)
            ->where('bank_transaction_matches.tech_settlement_id', $settlement->id)
            ->when($excluding, fn (Builder $q) => $q->where('bank_transaction_matches.bank_transaction_id', '!=', $excluding->id))
            ->selectRaw('coalesce(sum(abs(bank_transactions.amount_cents)), 0) as claimed')
            ->value('claimed');

        return max(0, (int) $settlement->amount_cents - $claimed);
    }

    /**
     * "Jose Martinez — To pay $150.00 (owed $200.00 − balance $50.00)" when the
     * payout was netted against a cash balance the tech owed us; plain
     * "Jose Martinez — $150.00" when it wasn't; "Warranty · Jose Martinez —
     * $80.00" for a warranty return-visit payout. Appends the settlement's own
     * day only when it differs from the transaction's (normally it won't, since
     * payoutOptions only offers same-day settlements).
     */
    private static function payoutLabel(TechSettlement $s, BankTransaction $record): string
    {
        $name = $s->technician?->name ?? 'Unknown tech';
        $amount = Money::format((int) $s->amount_cents);

        $label = $s->type === 'warranty_payout'
            ? "Warranty · {$name} — {$amount}"
            : ((int) $s->balance_before_cents > 0
                ? "{$name} — To pay {$amount} (owed ".Money::format((int) $s->tech_pay_owed_cents).' − balance '.Money::format((int) $s->balance_before_cents).')'
                : "{$name} — {$amount}");

        $day = $s->for_date ?: $s->settled_at?->copy()->timezone(config('glass.timezone'))->format('Y-m-d');
        if ($day && $day !== $record->matchDate()) {
            $label .= ' ('.Carbon::parse($day)->format('M j').')';
        }

        // Same trailing footnote as paymentLineLabel() when a prior Zelle
        // already claimed part of this settlement — otherwise a $370 payout
        // paid via $180 + $190 would read as fully up for grabs after the
        // first is matched.
        $remaining = self::remainingCentsForSettlement($s, $record);
        if ($remaining < (int) $s->amount_cents) {
            $label .= ' · '.Money::format($remaining).' left to match';
        }

        return $label;
    }

    /** True when the picked settlement's amount doesn't equal the transaction's — the mismatch note becomes required. */
    private static function isPayoutMismatch(mixed $settlementId, BankTransaction $record): bool
    {
        if (! $settlementId) {
            return false;
        }

        $settlement = TechSettlement::find($settlementId);

        return $settlement !== null && (int) $settlement->amount_cents !== (int) $record->amount_cents;
    }

    // ---- Shared date-window helpers ----

    /** UTC datetime range spanning the full Pacific day of a Y-m-d bank date. */
    private static function sameDayRange(string $date): array
    {
        return self::dayRange($date, 0, 0);
    }

    /** UTC datetime range spanning $daysBefore before to $daysAfter after a Y-m-d bank date (Pacific days). */
    private static function dayRange(string $date, int $daysBefore, int $daysAfter): array
    {
        $day = Carbon::parse($date, config('glass.timezone'));

        return [
            $day->copy()->subDays($daysBefore)->startOfDay()->utc(),
            $day->copy()->addDays($daysAfter)->endOfDay()->utc(),
        ];
    }

    /** The logged-in Employee (the auth model IS Employee in this app). */
    private static function actorId(): ?int
    {
        return auth()->id();
    }

    private static function viewerIsSuperAdmin(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }
}
