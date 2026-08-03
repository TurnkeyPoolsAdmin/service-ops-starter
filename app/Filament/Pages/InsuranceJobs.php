<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Jobs\JobResource;
use App\Filament\Resources\Jobs\Schemas\JobForm;
use App\Models\Job;
use App\Services\JobLifecycle;
use App\Support\Money;
use App\Support\Timezone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Insurance Jobs — every job flagged as insurance, with the carrier, the claim
 * number, what the carrier is expected to pay and what actually landed. The
 * expected-minus-actual across unpaid claims is the outstanding insurance
 * receivable, totalled in the subheading. Once the check comes in you "Mark
 * insurance paid" here and record the amount received. Reached via the Admin >
 * Finance hub.
 *
 * The claim details themselves are edited on the job (its Insurance section);
 * this is the finance-side roll-up over Job::scopeInsurance + the existing
 * recordInsurancePayment flow (also on the job's Actions menu).
 */
class InsuranceJobs extends Page implements HasTable
{
    use GatedByPermission;
    use InteractsWithTable;

    protected static function requiredPermission(): string
    {
        return 'record_payment';
    }

    // Reached via the Admin > Finance hub, not its own sidebar item.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected string $view = 'filament.pages.insurance-jobs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $title = 'Insurance Jobs';

    protected static ?string $navigationLabel = 'Insurance Jobs';

    protected static ?int $navigationSort = 22;

    /**
     * Leads with the outstanding receivable — the sum of expected payouts across
     * claims the carrier hasn't paid yet. Claims with no expected amount entered
     * can't be counted, so they're called out separately rather than silently
     * understating the total.
     */
    public function getSubheading(): ?string
    {
        $outstanding = (int) Job::query()->insuranceOutstanding()->sum('insurance_expected_cents');
        $unquantified = Job::query()->insuranceUnpaid()
            ->where(fn ($q) => $q->whereNull('insurance_expected_cents')->orWhere('insurance_expected_cents', 0))
            ->count();

        $lines = [Money::format($outstanding).' outstanding from carriers.'];

        if ($unquantified > 0) {
            $lines[] = $unquantified.' unpaid '.str('claim')->plural($unquantified)
                .' still '.($unquantified === 1 ? 'has' : 'have').' no expected payout recorded (not counted above) '
                .'- add it in the job\'s Insurance section.';
        }

        $lines[] = 'When the carrier\'s check comes in, mark it paid and record the amount received.';

        return implode(' ', $lines);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Job::query()->insurance()->with(['entity', 'technician']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('entity.code')->label('Loc')->badge()->placeholder('—'),
                TextColumn::make('customer_name_snapshot')->label('Customer')->weight('bold')
                    ->description(fn (Job $r) => $r->customer_phone_snapshot)
                    // Straight through to the job, where the claim details are edited.
                    ->url(fn (Job $r) => JobResource::getUrl('view', ['record' => $r]))
                    ->color('primary')
                    ->searchable(['customer_name_snapshot', 'customer_phone_snapshot']),
                TextColumn::make('insurance_carrier')->label('Carrier')
                    ->placeholder('—')
                    ->searchable()->sortable(),
                TextColumn::make('insurance_claim_number')->label('Claim #')
                    ->placeholder('—')->copyable()
                    ->searchable(),
                TextColumn::make('scheduled_at')->label('Scheduled')
                    ->dateTime('M j, Y')->timezone(Timezone::display())
                    ->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('quote_amount_cents')->label('Deductible')
                    ->formatStateUsing(fn ($state) => Money::format($state))->alignEnd()
                    ->toggleable(),
                TextColumn::make('insurance_expected_cents')->label('Expected')
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state) : '—')
                    ->alignEnd()->sortable(),
                TextColumn::make('insurance_amount_cents')->label('Actual')
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state) : '—')
                    ->alignEnd(),
                TextColumn::make('insurance_outstanding')->label('Outstanding')
                    ->state(fn (Job $r) => $r->insuranceOutstandingCents())
                    ->formatStateUsing(fn ($state) => $state > 0 ? Money::format($state) : '—')
                    ->alignEnd()
                    ->color(fn ($state) => $state > 0 ? 'warning' : null),
                TextColumn::make('insurance_status')->label('Status')
                    ->state(fn (Job $r) => $r->isInsurancePaid() ? 'Paid' : 'Awaiting')
                    ->badge()
                    ->color(fn (Job $r) => $r->isInsurancePaid() ? 'success' : 'warning'),
                TextColumn::make('insurance_paid_at')->label('Paid on')
                    ->dateTime('M j, Y')->timezone(Timezone::display())
                    ->placeholder('—')->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('insurance_paid')
                    ->label('Carrier payment')
                    ->placeholder('All insurance jobs')
                    ->trueLabel('Paid by carrier')
                    ->falseLabel('Awaiting carrier')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('insurance_paid_at'),
                        false: fn ($query) => $query->whereNull('insurance_paid_at'),
                        blank: fn ($query) => $query,
                    )
                    ->default(false),
            ])
            ->recordActions([
                $this->markPaidAction(),
            ])
            ->emptyStateHeading('No insurance jobs')
            ->emptyStateDescription('Jobs flagged as insurance at intake (or on the job\'s Money section) show here.')
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck);
    }

    /** Record the carrier's payment once the check arrives. */
    protected function markPaidAction(): Action
    {
        return Action::make('markInsurancePaid')
            ->label('Mark insurance paid')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('success')
            ->visible(fn (Job $record) => ! $record->isInsurancePaid())
            ->modalHeading(fn (Job $record) => 'Insurance payment — '.$record->customer_name_snapshot)
            ->modalSubmitActionLabel('Record payment')
            // Pre-filled from the claim details on the job, so a carrier paying
            // exactly what it quoted is a one-click confirm.
            ->fillForm(fn (Job $record) => [
                'amount' => $record->insurance_expected_cents !== null
                    ? $record->insurance_expected_cents / 100 : null,
                'insurance_carrier' => $record->insurance_carrier,
                'insurance_claim_number' => $record->insurance_claim_number,
            ])
            ->schema([
                TextInput::make('insurance_carrier')->label('Insurance carrier')
                    ->datalist(JobForm::INSURANCE_CARRIERS)->maxLength(120),
                TextInput::make('insurance_claim_number')->label('Claim number')->maxLength(120),
                TextInput::make('amount')->label('Insurance amount received ($)')
                    ->numeric()->prefix('$')->required()
                    ->helperText(fn (Job $record) => $record->insurance_expected_cents
                        ? 'Expected '.Money::format($record->insurance_expected_cents)
                            .' - change it if the carrier paid something else.'
                        : null),
                TextInput::make('reference')->label('Check / payment reference'),
                Textarea::make('insurance_notes')->label('Notes')->rows(2),
            ])
            ->action(function (Job $record, array $data) {
                app(JobLifecycle::class)->recordInsurancePayment(
                    $record,
                    Money::parseToCents((string) $data['amount']) ?? 0,
                    $data['reference'] ?? null,
                    $data['insurance_notes'] ?? null,
                    auth()->user(),
                    $data['insurance_carrier'] ?? null,
                    $data['insurance_claim_number'] ?? null,
                );

                Notification::make()->title('Insurance payment recorded')->success()->send();
            });
    }
}
