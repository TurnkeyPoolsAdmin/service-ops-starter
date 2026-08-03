<?php

namespace App\Filament\Pages;

use App\Enums\IssueType;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Filament\Resources\Jobs\JobResource;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Models\BankTransaction;
use App\Models\Issue;
use App\Models\Job;
use App\Models\MessageTemplate;
use App\Models\StripePayout;
use App\Models\VendorDocument;
use App\Models\VendorDocumentLine;
use App\Support\Money;
use App\Support\Timezone;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * "Anything the dispatch team does that feels off" — a super-admin-only review
 * queue over App\Models\Issue. Deleted jobs, cancelled jobs, and bank
 * transactions matched to a tech payout for the wrong amount all raise an
 * Issue (see JobLifecycle::deleteCompletely/cancel/markLost and
 * BankTransactionsTable::raiseMismatchIssue); this page is where a super
 * admin reviews and clears them. Gated on `view_issues`, which is granted to
 * no role — only the Gate::before super_admin bypass ever passes it.
 *
 * Subviews are a plain #[Url] string ($view) rather than Filament table tabs
 * (this is a Page, not a Resource ListRecords — there's no getTabs() here);
 * header actions double as tab buttons, each flipping $view and re-querying,
 * the same "page state the query closure reads at request time" pattern
 * TechPayments uses for its date.
 *
 * Clearing is deliberately one-at-a-time (no bulk action) and cleared issues
 * leave the page entirely — the super admin looks each one in the eye before
 * it goes. The cleared row (with its snapshot) stays in the table as a quiet
 * audit trail, it just has no UI.
 */
class Issues extends Page implements HasTable
{
    use GatedByPermission;
    use InteractsWithTable;

    private const VIEWS = ['deleted', 'canceled', 'mismatch', 'warranty_tech', 'warranty_pay', 'unpaid', 'unordered', 'part_glass', 'xpro', 'translation', 'mygrant_charge', 'mygrant_line', 'igc_credit', 'stripe_payout', 'fields_changed'];

    protected static function requiredPermission(): string
    {
        return 'view_issues';
    }

    protected string $view = 'filament.pages.issues';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $title = 'Issues';

    protected static ?string $navigationLabel = 'Issues';

    protected static ?int $navigationSort = 2;

    /** Which subview is showing: deleted | canceled | mismatch. */
    #[Url]
    public string $subview = '';

    public function mount(): void
    {
        if (! in_array($this->subview, self::VIEWS, true)) {
            $this->subview = $this->defaultSubview();
        }
    }

    /** The first tab with open items, else "deleted". */
    private function defaultSubview(): string
    {
        foreach (self::VIEWS as $key) {
            if (Issue::query()->open()->where('type', self::typeFor($key)->value)->exists()) {
                return $key;
            }
        }

        return 'deleted';
    }

    private static function typeFor(string $key): IssueType
    {
        return match ($key) {
            'deleted' => IssueType::JobDeleted,
            'canceled' => IssueType::JobCanceled,
            'mismatch' => IssueType::TransactionMismatch,
            'warranty_tech' => IssueType::WarrantyTechChanged,
            'warranty_pay' => IssueType::WarrantyTechPay,
            'unpaid' => IssueType::JobCompletedUnpaid,
            'unordered' => IssueType::PartNotOrdered,
            'part_glass' => IssueType::PartGlassMismatch,
            'xpro' => IssueType::XproUncollected,
            'translation' => IssueType::TranslationStale,
            'mygrant_charge' => IssueType::MygrantChargeMismatch,
            'mygrant_line' => IssueType::MygrantLineUnmatched,
            'igc_credit' => IssueType::IgcCreditUnmatched,
            'stripe_payout' => IssueType::StripePayoutResolutionExhausted,
            'fields_changed' => IssueType::MatchedTransactionFieldsChanged,
            default => IssueType::JobDeleted,
        };
    }

    private static function labelFor(string $key): string
    {
        return match ($key) {
            'deleted' => 'Deleted jobs',
            'canceled' => 'Cancelled jobs',
            'mismatch' => 'Mismatched transactions',
            'warranty_tech' => 'Warranty - diff tech',
            'warranty_pay' => 'Warranty - extra pay',
            'unpaid' => 'Completed - unpaid',
            'unordered' => 'Part not ordered',
            'part_glass' => 'Part vs glass',
            'xpro' => 'Xpro - overdue',
            'translation' => 'Spanish review',
            'mygrant_charge' => 'Mygrant - charge',
            'mygrant_line' => 'Mygrant - no job',
            'igc_credit' => 'IGC - unpaired credit',
            'stripe_payout' => 'Stripe - resolution failed',
            'fields_changed' => 'Matched txn - fields changed',
        };
    }

    /**
     * The subview tab strip, rendered as a wrapping button row in the Blade view
     * (not header actions — nine of those overflowed the page off-screen). One
     * entry per subview with its open-issue count and whether it's active.
     *
     * @return array<int, array{key: string, label: string, count: int, active: bool}>
     */
    public function subviewTabs(): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => self::labelFor($key),
            'count' => self::countFor($key),
            'active' => $this->subview === $key,
        ], self::VIEWS);
    }

    private static function countFor(string $key): int
    {
        return Issue::query()->open()->where('type', self::typeFor($key)->value)->count();
    }

    public function switchSubview(string $key): void
    {
        if (! in_array($key, self::VIEWS, true)) {
            return;
        }

        $this->subview = $key;
        $this->flushCachedTableRecords();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Issue::query()->open()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        return $table
            // Closure query (not an eager builder) for the same reason as
            // TechPayments' date range: the Table is built once per request,
            // before a header action's closure can mutate $this->subview.
            ->query(fn (): Builder => Issue::query()
                ->with('raisedBy')
                ->latest()
                ->open()
                ->where('type', self::typeFor($this->subview)->value))
            ->columns([
                TextColumn::make('created_at')->label('Raised')
                    ->dateTime('M j, Y g:ia')->timezone(Timezone::display())->sortable(),
                TextColumn::make('type')->label('Type')->badge()
                    ->formatStateUsing(fn (IssueType $state) => $state->label())
                    ->color(fn (IssueType $state) => $state->color()),
                TextColumn::make('what_happened')->label('What happened')
                    ->state(fn (Issue $record) => self::whatHappened($record))
                    ->wrap()->limit(90)
                    ->tooltip(fn (Issue $record) => self::whatHappened($record)),
                TextColumn::make('raisedBy.name')->label('By')->placeholder('—'),
            ])
            ->recordActions([
                $this->viewDetailsAction(),
                $this->translationStillFineAction(),
                $this->clearAction(),
            ])
            ->emptyStateHeading('Nothing to review')
            ->emptyStateDescription('Issues show up here when a job is deleted or cancelled, or a bank '
                .'match comes in for a different amount than expected.')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    // ---- Row actions ----

    /** Read-only view of everything captured about the issue, plus a link to the subject if it still exists. */
    protected function viewDetailsAction(): Action
    {
        return Action::make('viewDetails')
            ->label('View')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->modalHeading(fn (Issue $record) => $record->type->label())
            ->schema([
                Placeholder::make('details')->label('')
                    ->content(fn (Issue $record) => self::detailsHtml($record)),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * "The English edit was cosmetic, the Spanish still says the right thing."
     * Bumps the Spanish row's updated_at past the English one WITHOUT editing
     * the body, which is the only way to heal the condition when there's nothing
     * to re-translate (Eloquent won't touch timestamps on a no-change save). A
     * plain Clear would just have the sweep raise it again an hour later.
     */
    protected function translationStillFineAction(): Action
    {
        return Action::make('translationStillFine')
            ->label('Spanish still fine')
            ->icon(Heroicon::OutlinedHandThumbUp)
            ->color('gray')
            ->visible(fn (Issue $record) => $record->type === IssueType::TranslationStale
                && $record->status === 'open'
                && $record->subject_type === MessageTemplate::class)
            ->requiresConfirmation()
            ->modalHeading('Spanish is still accurate?')
            ->modalDescription('Marks this translation reviewed as-is — no edit needed. It leaves the queue and '
                .'comes back only if the English is edited again.')
            ->modalSubmitActionLabel('Yes, it still reads right')
            ->action(function (Issue $record) {
                $template = MessageTemplate::find($record->subject_id);

                if ($template === null) {
                    Notification::make()->title('That template no longer exists')->danger()->send();

                    return;
                }

                $template->markTranslationReviewed();
                $record->clear(auth()->id(), 'Spanish confirmed still accurate — no edit needed.');

                Notification::make()->title('Marked reviewed')->success()->send();
            });
    }

    /** Super admin's "reviewed and done" — purges a soft-deleted job's row for good. */
    protected function clearAction(): Action
    {
        return Action::make('clear')
            ->label('Clear')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('primary')
            ->visible(fn (Issue $record) => $record->status === 'open')
            ->requiresConfirmation()
            ->modalHeading('Clear this issue?')
            ->modalDescription(fn (Issue $record) => self::clearDescription($record))
            ->modalSubmitActionLabel('Clear')
            ->schema([
                Textarea::make('resolution_note')->label('Note (optional)')->rows(2),
            ])
            ->action(function (Issue $record, array $data) {
                self::purgeJobIfDeleted($record);
                $record->clear(auth()->id(), $data['resolution_note'] ?? null);

                Notification::make()->title('Issue cleared')->success()->send();
            });
    }

    private static function clearDescription(Issue $record): string
    {
        return $record->type === IssueType::JobDeleted
            ? 'The deleted job will be permanently removed — this cannot be undone. A snapshot of it stays on this issue.'
            : 'Marks this reviewed and done — it leaves the queue.';
    }

    /** Permanently purge the job behind a job_deleted issue, if it's still (soft-)around. */
    private static function purgeJobIfDeleted(Issue $record): void
    {
        if ($record->type !== IssueType::JobDeleted || $record->subject_type !== Job::class) {
            return;
        }

        $job = Job::withTrashed()->find($record->subject_id);

        if ($job !== null && $job->trashed()) {
            $job->forceDelete();
        }
    }

    // ---- Display helpers ----

    /** One-line summary for the table + tooltip. */
    private static function whatHappened(Issue $record): string
    {
        $d = $record->details ?? [];

        return match ($record->type) {
            IssueType::JobDeleted, IssueType::JobCanceled => self::jobIssueLine($d),
            IssueType::TransactionMismatch => self::mismatchLine($d),
            IssueType::WarrantyTechChanged, IssueType::WarrantyTechPay => self::warrantyLine($record->type, $d),
            IssueType::JobCompletedUnpaid => self::unpaidLine($d),
            IssueType::PartNotOrdered => self::unorderedLine($d),
            IssueType::PartGlassMismatch => self::partGlassLine($d),
            IssueType::XproUncollected => self::xproLine($d),
            IssueType::TranslationStale => self::translationLine($d),
            IssueType::MygrantChargeMismatch => self::mygrantChargeLine($d),
            IssueType::MygrantLineUnmatched => self::mygrantLineLine($d),
            IssueType::IgcCreditUnmatched => self::igcCreditLine($d),
            IssueType::StripePayoutResolutionExhausted => self::stripePayoutResolutionLine($d),
            IssueType::MatchedTransactionFieldsChanged => self::fieldsChangedLine($d),
        };
    }

    private static function fieldsChangedLine(array $d): string
    {
        $bits = array_filter([
            $d['txn_description'] ?? null,
            isset($d['changed_fields']) ? 'changed: '.$d['changed_fields'] : null,
            isset($d['old_amount_cents']) || isset($d['new_amount_cents'])
                ? Money::format((int) ($d['old_amount_cents'] ?? 0)).' -> '.Money::format((int) ($d['new_amount_cents'] ?? 0))
                : null,
            isset($d['old_date']) || isset($d['new_date'])
                ? ($d['old_date'] ?? '?').' -> '.($d['new_date'] ?? '?')
                : null,
        ], fn ($v) => filled($v));

        return implode(' — ', $bits);
    }

    private static function stripePayoutResolutionLine(array $d): string
    {
        $bits = array_filter([
            isset($d['stripe_payout_id']) ? $d['stripe_payout_id'] : 'Payout',
            isset($d['amount_cents']) ? Money::format((int) $d['amount_cents']) : null,
            isset($d['resolution_attempts']) ? $d['resolution_attempts'].' attempt(s), gave up' : null,
            filled($d['resolution_error'] ?? null) ? 'last error: '.$d['resolution_error'] : null,
        ], fn ($v) => filled($v));

        return implode(' — ', $bits);
    }

    private static function igcCreditLine(array $d): string
    {
        $bits = array_filter([
            isset($d['document_number']) ? '#'.$d['document_number'] : 'Credit',
            $d['amount'] ?? null,
            isset($d['document_date']) ? 'dated '.$d['document_date'] : null,
            'no original invoice found within 30 days',
        ], fn ($v) => filled($v));

        return implode(' — ', $bits);
    }

    private static function mygrantChargeLine(array $d): string
    {
        $state = $d['state'] ?? null;
        $amountBit = match ($state) {
            'short' => isset($d['shortfall_cents']) ? 'short '.Money::format((int) $d['shortfall_cents']) : null,
            'over' => isset($d['overage_cents']) ? 'over by '.Money::format((int) $d['overage_cents']) : null,
            default => 'ambiguous match',
        };

        $bits = array_filter([
            $d['txn_description'] ?? 'Mygrant charge',
            isset($d['charge_cents']) ? Money::format((int) $d['charge_cents']) : null,
            $amountBit,
            isset($d['txn_date']) ? 'charged '.$d['txn_date'] : null,
        ], fn ($v) => filled($v));

        return implode(' — ', $bits);
    }

    private static function mygrantLineLine(array $d): string
    {
        $bits = array_filter([
            $d['part_number'] ?? 'Part',
            $d['order_date'] ?? null,
            $d['amount'] ?? null,
            $d['warehouse'] ?? null,
            'no job decided 3+ days after billing',
        ], fn ($v) => filled($v));

        return implode(' — ', $bits);
    }

    private static function xproLine(array $d): string
    {
        $bits = array_filter([
            isset($d['job_number']) ? '#'.$d['job_number'] : null,
            $d['customer_name'] ?? 'Customer',
            isset($d['xpro_owed_cents']) ? Money::format((int) $d['xpro_owed_cents']).' to collect back' : null,
            filled($d['xpro_method'] ?? null) ? 'via '.$d['xpro_method'] : null,
            filled($d['due_by'] ?? null) ? 'was due '.$d['due_by'] : null,
        ], fn ($v) => filled($v));

        return implode(' — ', $bits);
    }

    private static function translationLine(array $d): string
    {
        $bits = array_filter([
            $d['template'] ?? 'Template',
            filled($d['english_edited'] ?? null) ? 'English edited '.$d['english_edited'] : 'English edited',
            filled($d['spanish_reviewed'] ?? null) ? 'Spanish last touched '.$d['spanish_reviewed'] : null,
        ], fn ($v) => filled($v));

        return implode(' — ', $bits);
    }

    private static function unpaidLine(array $d): string
    {
        $bits = array_filter([
            isset($d['job_number']) ? '#'.$d['job_number'] : null,
            $d['customer_name'] ?? 'Customer',
            isset($d['balance_due_cents']) ? Money::format((int) $d['balance_due_cents']).' still owed' : null,
            isset($d['completed_on']) ? 'completed '.$d['completed_on'] : null,
        ], fn ($v) => filled($v));

        return implode(' — ', $bits);
    }

    private static function unorderedLine(array $d): string
    {
        $bits = array_filter([
            isset($d['job_number']) ? '#'.$d['job_number'] : null,
            $d['customer_name'] ?? 'Customer',
            $d['glass_position'] ?? null,
            'scheduled today - part not ordered',
        ], fn ($v) => filled($v));

        return implode(' — ', $bits);
    }

    private static function partGlassLine(array $d): string
    {
        $bits = array_filter([
            isset($d['job_number']) ? '#'.$d['job_number'] : null,
            $d['customer_name'] ?? 'Customer',
            filled($d['part_number'] ?? null) && filled($d['part_reads'] ?? null)
                ? $d['part_number'].' reads '.strtolower((string) $d['part_reads'])
                : null,
            filled($d['glass_position'] ?? null) ? 'position is '.$d['glass_position'] : null,
            'scheduled on an override',
        ], fn ($v) => filled($v));

        return implode(' — ', $bits);
    }

    private static function warrantyLine(IssueType $type, array $d): string
    {
        $bits = array_filter([
            isset($d['job_number']) ? '#'.$d['job_number'] : null,
            $d['customer_name'] ?? 'Customer',
            $type === IssueType::WarrantyTechChanged
                ? trim(($d['original_tech'] ?? '?').' -> '.($d['assigned_tech'] ?? '?'))
                : (isset($d['tech_pay_cents']) ? Money::format((int) $d['tech_pay_cents']).' to '.($d['assigned_tech'] ?? 'tech') : null),
            $d['issue'] ?? null,
        ], fn ($v) => filled($v));

        return implode(' — ', $bits);
    }

    private static function jobIssueLine(array $d): string
    {
        $bits = array_filter([
            isset($d['job_number']) ? '#'.$d['job_number'] : null,
            $d['customer_name'] ?? 'Customer',
            isset($d['quote_cents']) ? Money::format((int) $d['quote_cents']) : null,
            $d['reason'] ?? null,
        ], fn ($v) => filled($v));

        return implode(' — ', $bits);
    }

    private static function mismatchLine(array $d): string
    {
        $bits = array_filter([
            $d['txn_description'] ?? null,
            isset($d['bank_amount_cents']) || isset($d['expected_amount_cents'])
                ? 'bank '.Money::format((int) ($d['bank_amount_cents'] ?? 0))
                    .' vs payout '.Money::format((int) ($d['expected_amount_cents'] ?? 0))
                : null,
            $d['tech_name'] ?? null,
            $d['note'] ?? null,
        ], fn ($v) => filled($v));

        return implode(' — ', $bits);
    }

    /** The full details map as key/value lines, plus a link to the subject when it still exists. */
    private static function detailsHtml(Issue $record): HtmlString
    {
        $rows = collect($record->details ?? [])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value, $key) => str_ends_with($key, '_body')
                ? self::bodyBlockHtml($key, (string) $value)
                : '<div style="padding:.15rem 0;"><strong>'
                    .e(self::humanizeKey($key)).':</strong> '.e(self::formatDetailValue($key, $value)).'</div>')
            ->implode('');

        if ($rows === '') {
            $rows = '<div style="color:#6b7280;">No details recorded.</div>';
        }

        $link = self::subjectLinkHtml($record);

        return new HtmlString(
            '<div style="font-size:.95rem;line-height:1.5;">'
            .$rows
            .($link ? '<div style="margin-top:.75rem;padding-top:.5rem;">'.$link.'</div>' : '')
            .'</div>'
        );
    }

    /**
     * A template body as its own readable block (line breaks preserved), so the
     * reviewer can read the English and Spanish stacked in the View modal
     * instead of two escaped one-liners. Inline styles, not Tailwind — this
     * panel runs Filament's prebuilt CSS with no custom theme compiled.
     */
    private static function bodyBlockHtml(string $key, string $body): string
    {
        return '<div style="margin:.5rem 0;"><strong>'.e(self::humanizeKey($key)).'</strong>'
            .'<div style="margin-top:.25rem;padding:.5rem .65rem;border-radius:.375rem;'
            .'background:rgba(148,163,184,.12);white-space:pre-wrap;font-size:.9rem;line-height:1.45;">'
            .e($body).'</div></div>';
    }

    private static function humanizeKey(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    private static function formatDetailValue(string $key, mixed $value): string
    {
        if (str_ends_with($key, '_cents') && is_numeric($value)) {
            return Money::format((int) $value);
        }

        if ($key === 'scheduled_at' && filled($value)) {
            return Carbon::parse($value)->timezone(Timezone::display())->format('M j, Y g:ia');
        }

        if (in_array($key, ['txn_date', 'for_date'], true) && filled($value)) {
            return Carbon::parse($value)->format('M j, Y');
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }

    /** Link to the subject record when it still exists — jobs link straight to the job (or say it'll be purged), transactions link to the ledger. */
    private static function subjectLinkHtml(Issue $record): ?string
    {
        if ($record->subject_type === Job::class) {
            $job = Job::withTrashed()->find($record->subject_id);

            if ($job === null) {
                return null;
            }

            if ($job->trashed()) {
                return '<span style="color:#6b7280;">This job will be permanently removed when this issue is cleared.</span>';
            }

            $url = JobResource::getUrl('view', ['record' => $job]);

            return '<a href="'.e($url).'" target="_blank" class="underline text-primary-600 dark:text-primary-400">View job</a>';
        }

        if ($record->subject_type === MessageTemplate::class) {
            $template = MessageTemplate::find($record->subject_id);

            if ($template === null) {
                return null;
            }

            $url = MessageTemplateResource::getUrl('edit', ['record' => $template]);

            return '<a href="'.e($url).'" target="_blank" class="underline text-primary-600 dark:text-primary-400">Edit the Spanish template</a>';
        }

        if ($record->subject_type === BankTransaction::class) {
            $txn = BankTransaction::find($record->subject_id);

            if ($txn === null) {
                return null;
            }

            $url = BankTransactionResource::getUrl('index');

            return '<a href="'.e($url).'" target="_blank" class="underline text-primary-600 dark:text-primary-400">View in Bank Transactions</a>';
        }

        if ($record->subject_type === StripePayout::class) {
            $payout = StripePayout::find($record->subject_id);

            if ($payout === null) {
                return null;
            }

            // No dedicated Filament page for StripePayout yet (Match-modal UI
            // wiring hasn't shipped) — nothing to link to, so just point at
            // where to look: the row itself (via tinker) or Stripe's own
            // dashboard for the payout id.
            return '<span style="color:#6b7280;">No dashboard page yet — check '
                .'<code>StripePayout::find('.$payout->id.')</code> or Stripe\'s dashboard for '
                .e($payout->stripe_payout_id).'.</span>';
        }

        if ($record->subject_type === VendorDocumentLine::class) {
            $line = VendorDocumentLine::find($record->subject_id);

            if ($line === null) {
                return null;
            }

            $url = PartsInvoices::getUrl();

            return '<a href="'.e($url).'" target="_blank" class="underline text-primary-600 dark:text-primary-400">View in Parts Invoices</a>';
        }

        if ($record->subject_type === VendorDocument::class) {
            $document = VendorDocument::find($record->subject_id);

            if ($document === null) {
                return null;
            }

            $url = PartsInvoices::getUrl();

            return '<a href="'.e($url).'" target="_blank" class="underline text-primary-600 dark:text-primary-400">View in Parts Invoices</a>';
        }

        return null;
    }
}
