<?php

namespace App\Filament\Resources\Jobs\Schemas;

use App\Enums\JobFunction;
use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Enums\TimePreference;
use App\Filament\Support\MoneyInput;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\Warehouse;
use App\Support\ClosedDays;
use App\Support\GlassPosition;
use App\Support\Money;
use App\Support\RequestedDay;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class JobForm
{
    /** Sentinel value for the warehouse Select's explicit "Other" option. */
    public const WAREHOUSE_OTHER = 'other';

    /**
     * Type-ahead suggestions for the insurance carrier field. Purely a
     * convenience datalist — the column is free text, so anything can be typed
     * and an unlisted carrier is perfectly valid.
     */
    public const INSURANCE_CARRIERS = [
        'State Farm', 'GEICO', 'Progressive', 'Allstate', 'USAA', 'Farmers',
        'Liberty Mutual', 'Nationwide', 'Travelers', 'Mercury', 'AAA',
        'Safelite Solutions', 'Lynx Services',
    ];

    /** Reasons for additional tech pay (stored value => display label). */
    public const ADDITIONAL_PAY_REASONS = [
        'distance' => 'Distance',
        'molding' => 'Molding',
        '2_man_job' => '2-man job',
        'multiple_visits' => 'Multiple visits / reschedule',
        'other' => 'Other',
    ];

    public static function configure(Schema $schema): Schema
    {
        // Sections mirror the job view's infolist groups so the full edit page
        // and the per-section inline edit modals stay in sync.
        return $schema
            ->components([
                Section::make('Summary')->columns(3)->schema(self::summaryFields()),
                Section::make('Parts, quote & labor')->columns(3)->schema(self::moneyFields()),
                Section::make('Insurance')->columns(3)->schema(self::insuranceFields())
                    ->visible(fn (?Job $record) => (bool) $record?->is_insurance),
                Section::make('Scheduling')->columns(2)->schema(self::schedulingFields()),
                Section::make('Notes')->schema(self::notesFields()),
                Section::make('Intake')->columns(2)->schema([
                    Textarea::make('vehicle_raw')->label('Raw vehicle text')->rows(2)->columnSpanFull(),
                    TextInput::make('status')->disabled()->dehydrated(false)
                        // Filament hydrates the form with the scalar value, so
                        // coerce back to the enum before labeling.
                        ->formatStateUsing(fn ($state) => ($state instanceof JobStatus
                            ? $state
                            : JobStatus::tryFrom((string) $state))?->label()),
                    Textarea::make('intake_raw_text')->label('Original WhatsApp text')->rows(4)
                        ->disabled()->dehydrated(false)->columnSpanFull(),
                ]),
            ]);
    }

    // ---- Reusable field groups, one per job-view section. Shared by the full
    // edit page (above) and the inline "Edit" modal on each view section. ----

    /** @return array<int, Component> */
    public static function summaryFields(): array
    {
        return [
            Select::make('entity_id')->label('Entity')
                ->options(Entity::pluck('name', 'id'))->required(),
            Select::make('booking_salesperson_id')->label('Booking salesperson')
                ->options(fn () => self::employees(JobFunction::Sales))->searchable(),
            TextInput::make('vehicle_year'),
            TextInput::make('vehicle_make'),
            TextInput::make('vehicle_model'),
            TextInput::make('vin')->label('VIN')->maxLength(17),
            Select::make('glass_position')->label('Glass position')
                ->options(fn (Get $get) => GlassPosition::optionsFor($get('glass_position')))
                ->searchable()
                ->live(),
            // Only an insurance WINDSHIELD auto-forces calibration - no other
            // glass carries the ADAS camera (mirrors Job::needsCalibration()).
            Toggle::make('needs_calibration')->label('Needs calibration')
                ->helperText(fn (Get $get) => self::calibrationForced($get)
                    ? 'Insurance windshields always need calibration.'
                    : 'Tick if this windshield needs ADAS calibration.')
                ->disabled(fn (Get $get) => self::calibrationForced($get)),
            // Two-visit install. Once the first day has been marked complete the
            // flag is locked — unticking it mid-flow would strand the job.
            Toggle::make('is_multi_day')->label('Multi-day install')
                ->helperText(fn (?Job $record) => $record?->multi_day_first_completed_at !== null
                    ? 'The first install day is already done - this can no longer be changed.'
                    : 'Takes two visits. Marking the first day complete prompts for the second date instead of finishing the job.')
                ->disabled(fn (?Job $record) => $record?->multi_day_first_completed_at !== null),
        ];
    }

    /** Calibration is auto-forced only on an insurance WINDSHIELD job. */
    private static function calibrationForced(Get $get): bool
    {
        return (bool) $get('is_insurance')
            && GlassPosition::isWindshield($get('glass_position'));
    }

    /** @return array<int, Component> */
    public static function moneyFields(): array
    {
        return [
            // Customer supplies their own glass — we only install it. Hides the
            // parts repeater here and switches off the entire parts side of the
            // job (order/prepay queues, warehouse, alerts). fromPartsInput()
            // clears any part already on file when this is on.
            Toggle::make('labor_only')
                ->label('Labor only (customer has the part)')
                ->columnSpanFull()
                ->live()
                ->helperText('The customer supplies the glass - we just install it. No part to order, prepay, source or cost out.'),
            // Not a model column — toPartsInput()/fromPartsInput() map this
            // to/from parts_json + part_number + part_cost_cents. The "Tech paid"
            // checkbox per row sums into tech_parts_cost_cents (reimbursed on top
            // of labor) — no need to re-type the amount.
            //
            // Cost is optional at intake (we don't know it until the part is
            // ordered — see JobActions::partsCostFields()) but, once the job IS
            // parts-ordered, a cost already on file can't be blanked out here —
            // that would silently undo what ordering just captured.
            Repeater::make('parts_input')
                ->label('Parts')
                ->columnSpanFull()
                ->addActionLabel('Add part')
                ->columns(4)
                ->visible(fn (Get $get) => ! $get('labor_only'))
                ->schema([
                    TextInput::make('label')->label('Part # / description')->columnSpan(2),
                    self::money('cost_cents', 'Cost ($)')
                        ->required(fn (?Job $record) => (bool) $record?->isPartsOrdered())
                        ->helperText(fn (?Job $record) => $record?->isPartsOrdered()
                            ? 'This part is already ordered - the cost can\'t be cleared here.'
                            : null),
                    Checkbox::make('tech_paid')->label('Tech paid')
                        ->helperText('Tech fronted this — reimburse on top of labor.'),
                ]),
            Toggle::make('is_insurance')->label('Insurance job')->live()
                ->helperText('Customer pays the deductible (the quote); the insurer pays the balance, tracked in the job\'s Insurance section.'),
            self::money('quote_amount_cents', 'Quote ($)')
                ->helperText(fn (Get $get) => $get('is_insurance') ? 'The deductible the customer pays.' : null),
            // Tech pay is FIXED at the assigned technician's base cost — it is
            // never typed per job. JobLifecycle::applyTechFixedCost writes it on
            // every assignment (and rewrites it on a tech swap), so the field is
            // read-only here: anything above base belongs on the Additional
            // labor line, where a reason is required. That way the End-of-Day
            // payout always explains why a tech got more than their base.
            // disabled() alone would drop the value on save — dehydrated() keeps
            // the stored amount submitting.
            self::money('tech_pay_amount_cents', 'Tech pay - base ($)')
                ->disabled()
                ->dehydrated()
                ->helperText(fn (?Job $record) => $record?->tech_paid_at !== null
                    ? 'Payout already recorded - this is the amount paid.'
                    : 'Fixed at the assigned tech\'s base cost. Put extra pay on the Additional labor line.'),
            self::money('additional_labor_cents', 'Additional labor ($)')
                ->live(onBlur: true)
                ->helperText('Extra pay on top of the base - distance, molding, a 2-man job.'),
            Select::make('additional_labor_reason')
                ->label('Additional pay reason')
                ->options(self::ADDITIONAL_PAY_REASONS)
                ->native(false)
                ->placeholder('Select a reason')
                // Extra pay must say why: the reason rides the End-of-Day
                // breakdown, so an unexplained amount can't reach a payout.
                ->required(fn (Get $get) => self::hasAdditionalLabor($get))
                ->helperText(fn (Get $get) => self::hasAdditionalLabor($get)
                    ? 'Required - say why the tech gets extra pay.'
                    : null),
            Placeholder::make('total_tech_pay')
                ->label('Total pay')
                ->content(fn (Get $get) => Money::format(self::totalTechPayCents($get)))
                ->helperText('Base + additional labor. This is what the End-of-Day summary pays the tech.'),
            // How the customer said they'll pay. Set it here ahead of time and
            // "Mark work complete" pre-fills it, firing the matching "Job
            // Complete - …" text. Only the five methods that have a completion
            // text (not the Xpro-collected ones).
            Select::make('payment_preference')
                ->label('How will the customer pay?')
                ->options([
                    PaymentMethod::Zelle->value => 'Zelle',
                    PaymentMethod::Stripe->value => 'Credit card (Stripe link)',
                    PaymentMethod::Venmo->value => 'Venmo',
                    PaymentMethod::Cash->value => 'Cash',
                    PaymentMethod::Check->value => 'Check',
                ])
                ->native(false)
                ->placeholder('Not confirmed yet')
                // Moot once the money is in — and showing it next to the recorded
                // payment method reads like the job was paid two ways. Mirrors the
                // infolist's "Expected payment" entry, which hides the same way.
                ->visible(fn (?Job $record) => ! $record?->isFullyPaid())
                ->helperText('Pre-fills "Mark work complete" and picks which Job Complete text is sent.'),
        ];
    }

    /**
     * True once a positive additional-labor amount is on the form. Money fields
     * hold DOLLARS in form state (MoneyInput dehydrates to cents), so read the
     * raw state as a float rather than expecting the stored integer.
     */
    private static function hasAdditionalLabor(Get $get): bool
    {
        return self::dollarsToCents($get('additional_labor_cents')) > 0;
    }

    /** Base tech pay + additional labor, in cents, from live form state. */
    private static function totalTechPayCents(Get $get): int
    {
        return self::dollarsToCents($get('tech_pay_amount_cents'))
            + self::dollarsToCents($get('additional_labor_cents'));
    }

    /** A money field's dollar form state as integer cents (0 when empty). */
    private static function dollarsToCents(mixed $state): int
    {
        return blank($state) ? 0 : (int) round(((float) $state) * 100);
    }

    /**
     * The claim's own details, on an insurance job only. The customer's
     * deductible is the ordinary quote (moneyFields above) — this is everything
     * about the CARRIER's side: who they are, the claim we're billing under, and
     * what they said they'd pay. The actual payout isn't editable here; it's
     * written by "Record insurance payment" when the check lands.
     *
     * @return array<int, Component>
     */
    public static function insuranceFields(): array
    {
        return [
            TextInput::make('insurance_carrier')->label('Insurance carrier')
                ->maxLength(120)
                ->datalist(self::INSURANCE_CARRIERS)
                ->helperText('Who is paying the claim - e.g. State Farm, GEICO, Safelite Solutions.'),
            TextInput::make('insurance_claim_number')->label('Claim number')
                ->maxLength(120),
            self::money('insurance_expected_cents', 'Expected payout ($)')
                ->helperText('What the carrier said they would pay us, on top of the customer\'s deductible.'),
        ];
    }

    /** @return array<int, Component> */
    public static function schedulingFields(): array
    {
        return [
            self::technicianSelect(),
            Textarea::make('service_address')->label('Service address')->rows(2)->columnSpanFull()->required(),
            TextInput::make('city')->label('City')
                ->helperText('City only — shown in the tech availability text.'),
            DatePicker::make('requested_day')->label('Requested day')
                ->native(false)
                ->displayFormat('D, M j, Y')
                ->closeOnDateSelection()
                // We're closed Sundays and on tagged holidays, so a customer
                // can't have asked for one.
                ->disabledDates(fn () => ClosedDays::upcoming())
                // Jobs taken before this was a calendar hold free text
                // ("Tomorrow", "next Fri") — show those as empty rather than
                // letting the picker choke on an unparseable state.
                ->formatStateUsing(fn ($state) => self::asDate($state))
                ->helperText('The date the customer asked for. Sundays and holidays are not selectable.'),
            ...self::requestedTimeFields(),
            ...self::warehouseFields(),
        ];
    }

    /**
     * The customer's time-of-day preference — a Select, plus the free-text
     * clock time it reveals when they named an exact one. Shared by intake and
     * the job form so a hand-typed job classifies the same as a parsed one.
     *
     * The Select is what the post-welcome scheduling ask branches on
     * (App\Support\SchedulingAsk): "No preference" gets ASKED for a time,
     * everything else gets ACKNOWLEDGED. ASAP counts as an answer, not a blank.
     *
     * @return array<int, Component>
     */
    public static function requestedTimeFields(): array
    {
        return [
            Select::make('requested_time_preference')->label('Requested time')
                ->options(TimePreference::options())
                ->default(TimePreference::None->value)
                ->native(false)
                ->live()
                // Legacy jobs have a null column — read their old free text so
                // the Select opens on what the customer actually asked for.
                // The column casts to the enum, so a section edit prefilled
                // from the record hands us a case, not its string value.
                ->formatStateUsing(fn ($state, ?Job $record) => ($state instanceof TimePreference ? $state->value : $state)
                    ?? TimePreference::normalize($record?->requested_time)?->value
                    ?? TimePreference::None->value)
                ->helperText('What the customer asked for. Drives the scheduling text sent after the welcome.'),
            TextInput::make('requested_time')->label('Specific time')
                ->visible(fn (Get $get) => $get('requested_time_preference') === TimePreference::Specific->value)
                ->required(fn (Get $get) => $get('requested_time_preference') === TimePreference::Specific->value)
                ->placeholder('2pm')
                ->helperText('The exact time they asked for.'),
            // Structured, unlike the specific time above: the AI scheduler
            // uses this hour as the earliest bound of the window it negotiates,
            // so it must never depend on parsing free text.
            Select::make('requested_after_time')->label('After what time?')
                ->options(TimePreference::afterTimeOptions())
                ->native(false)
                ->visible(fn (Get $get) => $get('requested_time_preference') === TimePreference::After->value)
                ->required(fn (Get $get) => $get('requested_time_preference') === TimePreference::After->value)
                ->helperText('Any time from here to close works for them.'),
        ];
    }

    /**
     * Warehouse picker + conditional "other" free text. The Select lists active
     * warehouses plus an explicit "Other" option; picking Other reveals the
     * free-text field and makes it required, so an "other" warehouse is never
     * left blank. On save the Other sentinel dehydrates to a null warehouse_id
     * (the location lives in warehouse_other), and warehouse_other is nulled
     * whenever a real warehouse is chosen.
     *
     * Both fields disappear entirely on a labor-only job — the customer brings
     * the glass, so there is no part of ours to source (and a required warehouse
     * would block scheduling on a job that never needs one).
     *
     * @return array<int, Component>
     */
    public static function warehouseFields(bool $required = false): array
    {
        $partsSourced = fn (?Job $record) => ! (bool) $record?->isLaborOnly();

        return [
            Select::make('warehouse_id')->label('Warehouse')
                ->options(fn () => Warehouse::where('active', true)->orderBy('name')->pluck('name', 'id')->all()
                    + [self::WAREHOUSE_OTHER => 'Other (not in list)'])
                ->searchable()
                ->live()
                ->visible($partsSourced)
                ->required(fn (?Job $record) => $required && $partsSourced($record))
                ->helperText('Where the parts are located.')
                // Preselect "Other" when editing a job that only has the free-text warehouse.
                ->formatStateUsing(fn ($state, ?Job $record) => $state
                    ?? (filled($record?->warehouse_other) ? self::WAREHOUSE_OTHER : null))
                ->dehydrateStateUsing(fn ($state) => $state === self::WAREHOUSE_OTHER ? null : $state),
            TextInput::make('warehouse_other')->label('Other warehouse (free text)')
                ->live()
                ->visible(fn (Get $get, ?Job $record) => $partsSourced($record)
                    && $get('warehouse_id') === self::WAREHOUSE_OTHER)
                ->required(fn (Get $get) => $get('warehouse_id') === self::WAREHOUSE_OTHER)
                ->helperText('Required when the warehouse is not in the list.')
                ->dehydrateStateUsing(fn ($state, Get $get) => $get('warehouse_id') === self::WAREHOUSE_OTHER ? $state : null),
        ];
    }

    /** @return array<int, Component> */
    public static function notesFields(): array
    {
        return [
            Textarea::make('notes')->hiddenLabel()->rows(4)->columnSpanFull()
                ->placeholder('Anything dispatch/the tech should know about this job.'),
        ];
    }

    public static function technicianSelect(): Select
    {
        return Select::make('technician_id')->label('Technician')
            ->options(fn () => self::employees(JobFunction::Technician))->searchable()
            ->live()
            // Tech pay is fixed at the assigned tech's base cost, so it FOLLOWS
            // the tech — swapping techs re-reads the new one's fixed cost rather
            // than leaving the old one's number behind. Mirrors
            // JobLifecycle::applyTechFixedCost, which does the same on save.
            // (Only takes effect where tech_pay is in the same form.)
            ->afterStateUpdated(function ($state, Set $set) {
                if (blank($state)) {
                    $set('tech_pay_amount_cents', null);

                    return;
                }
                $cents = Employee::whereKey($state)->value('fixed_cost_cents');
                $set('tech_pay_amount_cents', $cents !== null ? $cents / 100 : null);
            });
    }

    // ---- Shared transforms between form state and model columns ----

    /**
     * parts_json (+ legacy single part) -> the parts_input repeater rows
     * (cost in cents). Mirrors the storage shape used by fromPartsInput().
     */
    public static function toPartsInput(array $data): array
    {
        $parts = $data['parts_json'] ?? [];

        // Fall back to the legacy single part if a record predates parts_json.
        if ($parts === [] && ($data['part_number'] ?? null) !== null) {
            $parts = [['label' => $data['part_number'], 'cost_cents' => $data['part_cost_cents'] ?? null]];
        }

        return array_map(fn ($p) => [
            'label' => $p['label'] ?? null,
            'cost_cents' => $p['cost_cents'] ?? null,
            'tech_paid' => (bool) ($p['tech_paid'] ?? false),
        ], $parts);
    }

    /**
     * parts_input repeater rows -> parts_json + summed part_cost_cents + primary
     * part_number, dropping fully-blank rows. Removes parts_input from $data.
     *
     * A labor-only job has no part of ours at all, so every parts column is
     * cleared — the repeater is hidden in that state, and flipping the toggle on
     * a job that already had a part must not leave a stale cost behind (it would
     * still be subtracted from gross margin / ROAS).
     */
    public static function fromPartsInput(array $data): array
    {
        if (array_key_exists('labor_only', $data) && $data['labor_only']) {
            unset($data['parts_input']);
            $data['parts_json'] = null;
            $data['part_number'] = null;
            $data['part_cost_cents'] = null;
            $data['tech_parts_cost_cents'] = null;

            return $data;
        }

        $parts = [];
        $techPaidCents = 0;
        foreach ($data['parts_input'] ?? [] as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $cost = isset($row['cost_cents']) && $row['cost_cents'] !== '' && $row['cost_cents'] !== null
                ? (int) $row['cost_cents'] : null;
            if ($label === '' && $cost === null) {
                continue;
            }
            $techPaid = (bool) ($row['tech_paid'] ?? false);
            $part = ['label' => $label === '' ? null : $label, 'cost_cents' => $cost];
            // Only store the flag when set, keeping parts_json clean for the common case.
            if ($techPaid) {
                $part['tech_paid'] = true;
                $techPaidCents += $cost ?? 0;
            }
            $parts[] = $part;
        }

        $costs = array_filter(array_column($parts, 'cost_cents'), fn ($c) => $c !== null);

        $data['parts_json'] = $parts === [] ? null : $parts;
        $data['part_number'] = $parts[0]['label'] ?? null;
        $data['part_cost_cents'] = $costs === [] ? null : array_sum($costs);
        // Reimbursement to the tech = sum of the parts they checked (0 → null).
        $data['tech_parts_cost_cents'] = $techPaidCents > 0 ? $techPaidCents : null;

        unset($data['parts_input']);

        return $data;
    }

    /**
     * Keep the effective requested string (used by SMS templates) in sync with
     * the day/time edits: the effective value is just "<day> <time>". Only acts
     * when one of those keys is present in $data.
     */
    public static function syncRequestedDaytime(array $data): array
    {
        if (! array_key_exists('requested_day', $data)
            && ! array_key_exists('requested_time', $data)
            && ! array_key_exists('requested_after_time', $data)
            && ! array_key_exists('requested_time_preference', $data)) {
            return $data;
        }

        // The hour belongs to the After case alone — switching the preference
        // away from it must not leave a stale one behind for the AI scheduler
        // (or a later switch back) to pick up.
        if (array_key_exists('requested_time_preference', $data)
            && $data['requested_time_preference'] !== TimePreference::After->value) {
            $data['requested_after_time'] = null;
        }

        $day = trim((string) self::formatRequestedDay($data['requested_day'] ?? null));
        $dayTime = trim($day.' '.self::requestedTimeText($data));
        $data['requested_daytime_raw'] = $dayTime === '' ? null : $dayTime;

        return $data;
    }

    /**
     * The time half of the requested string: the specific clock time when one
     * was given, otherwise the preference's own wording ("Morning", "After
     * 10am"). Blank for "no preference" — and for legacy rows, the untouched
     * free text.
     */
    private static function requestedTimeText(array $data): string
    {
        $preference = TimePreference::tryFrom((string) ($data['requested_time_preference'] ?? ''));

        // After owns its own hour, so its wording wins over any free text the
        // row happens to still carry ("after 10am" from an older lead paste).
        if ($preference === TimePreference::After) {
            return $preference->shortLabel($data['requested_after_time'] ?? null);
        }

        $time = trim((string) ($data['requested_time'] ?? ''));
        if ($time !== '') {
            return $time;
        }

        return $preference?->shortLabel() ?? '';
    }

    /**
     * A requested day (Y-m-d from the picker) spelled out for humans/SMS —
     * "Monday, July 21". Legacy free-text values ("Tomorrow") pass through
     * unchanged; blank in, null out.
     */
    public static function formatRequestedDay(?string $day): ?string
    {
        return RequestedDay::spellOut($day);
    }

    /** Y-m-d if $state is a real date, else null (free text / blank). */
    private static function asDate($state): ?string
    {
        return RequestedDay::asDate($state);
    }

    private static function employees(JobFunction $fn): array
    {
        return Employee::active()->withJobFunction($fn)->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function money(string $field, string $label): TextInput
    {
        return MoneyInput::make($field, $label);
    }
}
