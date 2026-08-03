<?php

namespace App\Filament\Pages;

use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Jobs\Schemas\JobForm;
use App\Filament\Widgets\WarrantyPayoutsTable;
use App\Models\Employee;
use App\Models\Job;
use App\Models\TechSettlement;
use App\Services\JobLifecycle;
use App\Support\Money;
use App\Support\Timezone;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * End-of-Day Summary — one row per technician for the selected day (calendar,
 * defaults to today). Shows the jobs they completed that day, the pay they're
 * owed, the cash they collected (a credit), and the net. "Mark paid" settles
 * the tech's whole day in one click via JobLifecycle::settleTechDay, which
 * auto-offsets their rolling owed-cash balance: a day we'd owe them first pays
 * down what they owe us; a day they collected surplus cash rolls onto their
 * balance (see Tech Balances). Gated by record_tech_payout; super_admins bypass.
 */
class TechPayments extends Page implements HasTable
{
    use GatedByPermission;
    use InteractsWithTable;

    protected static function requiredPermission(): string
    {
        return 'record_tech_payout';
    }

    // Reached via the Admin > Finance hub, not its own sidebar item.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected string $view = 'filament.pages.tech-payments';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $title = 'End-of-Day Summary';

    protected static ?string $navigationLabel = 'End-of-Day Summary';

    protected static ?int $navigationSort = 20;

    /**
     * Selected day, Y-m-d in the display (Pacific) timezone. #[Url] lets other
     * pages deep-link a specific day (e.g. Bank Transactions "Matched to" links
     * a tech payout to ?date=2026-06-30).
     */
    #[Url]
    public ?string $date = null;

    public function mount(): void
    {
        if (! is_string($this->date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            $this->date = Timezone::now()->format('Y-m-d');
        }
    }

    public function getSubheading(): ?string
    {
        return Carbon::parse($this->date, Timezone::display())->format('l, F j, Y')
            .' — pay each tech their net for the day and mark it paid.';
    }

    /** Warranty visit pay (extra pay on a return visit) settles below the day's jobs. */
    protected function getFooterWidgets(): array
    {
        return [
            WarrantyPayoutsTable::class,
        ];
    }

    /** Date controls: step a day, pick a date, or snap back to today. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('previousDay')->label('Previous')
                ->icon(Heroicon::OutlinedChevronLeft)->color('gray')
                ->action(fn () => $this->goToDate(Carbon::parse($this->date)->subDay()->format('Y-m-d'))),
            Action::make('pickDate')
                ->label(fn () => Carbon::parse($this->date, Timezone::display())->format('M j, Y'))
                ->icon(Heroicon::OutlinedCalendarDays)->color('gray')
                ->fillForm(fn () => ['date' => $this->date])
                ->schema([
                    DatePicker::make('date')->label('Show summary for')
                        ->native(false)->required()->maxDate(Timezone::now()),
                ])
                ->action(fn (array $data) => $this->goToDate($data['date'])),
            Action::make('nextDay')->label('Next')
                ->icon(Heroicon::OutlinedChevronRight)->color('gray')
                ->disabled(fn () => Carbon::parse($this->date)->isToday())
                ->action(fn () => $this->goToDate(Carbon::parse($this->date)->addDay()->format('Y-m-d'))),
            Action::make('today')->label('Today')->color('gray')
                ->action(fn () => $this->goToDate(Timezone::now()->format('Y-m-d'))),
        ];
    }

    /** Move to another day. Flush the record cache so the re-render re-queries. */
    protected function goToDate(string $date): void
    {
        $this->date = $date;
        $this->flushCachedTableRecords();
    }

    public function table(Table $table): Table
    {
        // The query is a CLOSURE, not an eager builder, on purpose: Filament builds
        // the Table once per request during bootedInteractsWithTable() — which runs
        // BEFORE a header action's closure mutates $this->date. If the day range were
        // baked in at build time, Previous/Next would re-render with the OLD day's
        // query and each click would show the day you were just on (off-by-one lag).
        // Evaluating the range inside the closure reads the current $this->date at
        // query time instead. Total owed = labor (base + additional) + fronted parts.
        $labor = DB::raw('tech_pay_amount_cents + COALESCE(additional_labor_cents, 0) + COALESCE(tech_parts_cost_cents, 0)');

        return $table
            ->query(function () use ($labor): Builder {
                [$start, $end] = Timezone::localDayUtcRange($this->date ?? Timezone::now()->format('Y-m-d'));

                // Every tech who COMPLETED a job that day shows up — paid or not — so
                // you can step back a day or two and still see what happened.
                $completed = fn (Builder $q) => $q->whereNotNull('work_completed_at')
                    ->whereNotIn('status', [JobStatus::Canceled->value, JobStatus::Lost->value])
                    ->whereBetween('work_completed_at', [$start, $end]);
                // The still-open (not yet tech-paid) subset drives what's left to pay.
                $open = fn (Builder $q) => $q->settleable()->whereBetween('work_completed_at', [$start, $end]);

                return Employee::query()
                    ->whereHas('jobsAsTechnician', $completed)
                    ->withCount(['jobsAsTechnician as day_jobs_count' => $completed])
                    ->withCount(['jobsAsTechnician as day_open_count' => $open])
                    ->withSum(['jobsAsTechnician as day_labor_cents' => $completed], $labor)
                    ->withSum(['jobsAsTechnician as day_owed_cents' => $open], $labor);
            })
            ->defaultSort('name', 'asc')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Technician')->weight('bold')->searchable(),
                TextColumn::make('entity.code')->label('Loc')->badge()->placeholder('—'),
                TextColumn::make('day_jobs_count')->label('Jobs')->alignCenter()
                    ->visibleFrom('md'),
                TextColumn::make('day_labor_cents')->label('Pay owed')
                    ->state(fn (Employee $r) => Money::format((int) ($r->day_labor_cents ?? 0)))->alignEnd(),
                TextColumn::make('prior_balance')->label('Owes us (balance)')
                    ->state(fn (Employee $r) => Money::format($r->cashOwedCents()))
                    ->color(fn (Employee $r) => $r->cashOwedCents() > 0 ? 'danger' : 'gray')->alignEnd()
                    ->visibleFrom('md'),
                TextColumn::make('payout')->label('To pay')
                    ->state(fn (Employee $r) => self::isSettled($r) ? '—' : Money::format(self::payoutFor($r)))
                    ->badge()->color(fn (Employee $r) => (! self::isSettled($r) && self::payoutFor($r) > 0) ? 'success' : 'gray')
                    ->alignEnd(),
                TextColumn::make('status')->label('Status')
                    ->state(fn (Employee $r) => self::isSettled($r) ? 'Paid' : 'To pay')
                    ->badge()->color(fn (Employee $r) => self::isSettled($r) ? 'success' : 'warning')
                    ->alignCenter(),
            ])
            // Clicking a tech's row opens the job-by-job breakdown of their pay owed.
            ->recordAction('breakdown')
            ->recordActions([
                $this->breakdownAction(),
                $this->settleAction(),
                $this->undoAction(),
            ])
            ->emptyStateHeading('No completed jobs for this day')
            ->emptyStateDescription('Pick another date above. Techs appear here once they have jobs marked complete on the selected day.')
            ->emptyStateIcon(Heroicon::OutlinedBanknotes);
    }

    /** True when every job the tech completed that day is already tech-paid. */
    private static function isSettled(Employee $r): bool
    {
        return (int) ($r->day_open_count ?? 0) === 0;
    }

    /** Labor still owed for the day's open (not yet tech-paid) jobs. */
    private static function dayNetFor(Employee $r): int
    {
        return (int) ($r->day_owed_cents ?? 0);
    }

    /** What we actually hand the tech after auto-offsetting their balance. */
    private static function payoutFor(Employee $r): int
    {
        return max(0, self::dayNetFor($r) - $r->cashOwedCents());
    }

    /**
     * Read-only "what makes up this tech's pay owed" — the job-by-job breakdown
     * for the day (work line + a parts line whenever the tech fronted parts).
     * Opened by clicking the tech's row; works for settled techs too.
     */
    protected function breakdownAction(): Action
    {
        return Action::make('breakdown')
            ->label('View jobs')
            ->icon(Heroicon::OutlinedListBullet)
            ->color('gray')
            ->modalHeading(fn (Employee $record) => $record->name.' — '
                .Carbon::parse($this->date, Timezone::display())->format('M j, Y'))
            ->schema([
                Placeholder::make('breakdown')->label('')
                    ->content(fn (Employee $record) => $this->breakdownHtml($record)),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * Per-job breakdown of the day's pay owed for one tech. Lists every completed
     * job that day (paid or not) with a "Base" line, an "Additional labor" line
     * (with its reason) whenever the job carries extra pay, and a "Parts (tech
     * paid)" line when the tech fronted the parts — then totals for work, parts,
     * and the sum. A tech paid more than base can see exactly why.
     */
    private function breakdownHtml(Employee $record): HtmlString
    {
        $jobs = app(JobLifecycle::class)->techDayCompletedJobs($record, $this->date);

        $workTotal = 0;
        $partsTotal = 0;
        $rows = $jobs->map(function (Job $job) use (&$workTotal, &$partsTotal) {
            $work = $job->expected_tech_pay_cents;
            $parts = $job->tech_parts_reimbursement_cents;
            $extra = (int) ($job->additional_labor_cents ?? 0);
            $workTotal += $work;
            $partsTotal += $parts;

            // Once the payout is recorded markTechPaid folds the extra into
            // tech_pay_amount_cents and clears the separate line, so a settled
            // job correctly shows one "Work" figure again.
            if ($extra > 0) {
                $reason = JobForm::ADDITIONAL_PAY_REASONS[$job->additional_labor_reason]
                    ?? $job->additional_labor_reason;
                $lines = '<div style="padding:.1rem 0;">Base — <strong>'
                    .Money::format($work - $extra).'</strong></div>'
                    .'<div style="padding:.1rem 0;color:#b45309;">Additional labor'
                    .($reason ? ' ('.e($reason).')' : '').' — <strong>'.Money::format($extra).'</strong></div>';
            } else {
                $lines = '<div style="padding:.1rem 0;">Work — <strong>'.Money::format($work).'</strong></div>';
            }

            if ($parts > 0) {
                $lines .= '<div style="padding:.1rem 0;color:#2563eb;">Parts (tech paid) — <strong>'.Money::format($parts).'</strong></div>';
            }

            return '<div style="padding:.5rem 0;border-bottom:1px solid #f1f1f1;">'
                .'<div style="font-weight:600;">'.e($job->job_number).' — '.e($job->customer_name_snapshot ?: 'Customer').'</div>'
                .'<div style="margin-left:.75rem;font-size:.9rem;">'.$lines.'</div>'
                .'</div>';
        })->implode('');

        if ($rows === '') {
            $rows = '<div style="color:#6b7280;padding:.5rem 0;">No completed jobs for this day.</div>';
        }

        $partsTotalLine = $partsTotal > 0
            ? 'Parts fronted by tech: <strong>'.Money::format($partsTotal).'</strong><br>' : '';

        return new HtmlString(
            '<div style="font-size:.95rem;line-height:1.5;">'
            .$rows
            .'<div style="margin-top:.6rem;padding-top:.5rem;">'
            .'Work total: <strong>'.Money::format($workTotal).'</strong><br>'
            .$partsTotalLine
            .'<div style="margin-top:.3rem;font-size:1.05rem;">Pay owed: <strong>'.Money::format($workTotal + $partsTotal).'</strong></div>'
            .'</div>'
            .'</div>'
        );
    }

    /**
     * True when the day nets to a payout (we owe the tech) vs. purely settling
     * against their balance (they owe us) — drives "Mark paid" vs "Mark
     * confirmed" so the button never claims we paid someone when nothing changed
     * hands.
     */
    private static function isPayoutDay(Employee $r): bool
    {
        return self::payoutFor($r) > 0;
    }

    /** Settle this tech's whole day in one click (auto-offset balance). */
    protected function settleAction(): Action
    {
        return Action::make('settle')
            ->label(fn (Employee $record) => self::isPayoutDay($record) ? 'Mark paid' : 'Mark confirmed')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Employee $record) => ! self::isSettled($record))
            ->modalHeading(fn (Employee $record) => (self::isPayoutDay($record) ? 'Settle ' : 'Confirm ')
                .$record->name."'s day — "
                .Carbon::parse($this->date, Timezone::display())->format('M j, Y'))
            ->modalSubmitActionLabel(fn (Employee $record) => self::isPayoutDay($record) ? 'Mark paid' : 'Mark confirmed')
            ->modalWidth('md')
            ->schema([
                Placeholder::make('confirm')->label('')
                    ->content(function (Employee $record) {
                        $payout = self::payoutFor($record);

                        return new HtmlString($payout > 0
                            ? 'Confirm you paid <strong>'.e($record->name).'</strong> <strong>'
                                .Money::format($payout).'</strong> via Zelle.'
                            : 'Nothing to pay out — confirming just settles the day against <strong>'
                                .e($record->name).'</strong>\'s balance. No cash changes hands.');
                    }),
            ])
            ->action(function (Employee $record) {
                $settlement = app(JobLifecycle::class)->settleTechDay(
                    $record,
                    $this->date,
                    self::payoutFor($record) > 0 ? PaymentMethod::Zelle : null,
                    null,
                    null,
                    auth()->user(),
                );

                if ($settlement === null) {
                    Notification::make()->title('Nothing to settle')
                        ->body('This tech has no open jobs for this day.')->warning()->send();

                    return;
                }

                $body = $settlement->amount_cents > 0
                    ? 'Paid '.Money::format($settlement->amount_cents).' to '.$record->name.'.'
                    : $record->name.' now owes us '.Money::format($settlement->balance_after_cents).'.';

                Notification::make()->title('Day settled')->body($body)->success()->send();
            });
    }

    /** The still-undone eod_payout settlement for this tech + the selected day, if any. */
    private function latestSettlementFor(Employee $record): ?TechSettlement
    {
        return TechSettlement::where('technician_id', $record->id)
            ->where('for_date', $this->date)
            ->where('type', 'eod_payout')
            ->whereNull('undone_at')
            ->latest('id')
            ->first();
    }

    /**
     * Reverse a "Mark paid"/"Mark confirmed" click: restores the tech's balance
     * to what it was before and reopens exactly the jobs that settlement paid,
     * so — after fixing something like a tech-fronted-part flag ticked too late
     * — the day can be re-settled and picks up the corrected total.
     */
    protected function undoAction(): Action
    {
        return Action::make('undoSettle')
            ->label('Undo')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Employee $record) => self::isSettled($record) && $this->latestSettlementFor($record) !== null)
            ->modalHeading(fn (Employee $record) => 'Undo '.$record->name."'s settlement — "
                .Carbon::parse($this->date, Timezone::display())->format('M j, Y'))
            ->modalDescription('Reopens the day\'s jobs and restores the balance to what it was before this settlement. Use this to fix something (like a tech-fronted part flagged after the fact) and then re-settle.')
            ->modalSubmitActionLabel('Undo settlement')
            ->requiresConfirmation()
            ->action(function (Employee $record) {
                $settlement = $this->latestSettlementFor($record);

                if ($settlement === null) {
                    Notification::make()->title('Nothing to undo')
                        ->body('No settlement found for this tech on this day.')->warning()->send();

                    return;
                }

                try {
                    app(JobLifecycle::class)->undoSettleTechDay($settlement, auth()->user());
                } catch (\Throwable $e) {
                    Notification::make()->title('Could not undo')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Settlement undone')
                    ->body($record->name.'\'s day is open again — fix what\'s needed, then re-settle.')
                    ->success()->send();
            });
    }
}
