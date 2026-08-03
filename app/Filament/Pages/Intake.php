<?php

namespace App\Filament\Pages;

use App\Enums\JobFunction;
use App\Enums\Language;
use App\Enums\TimePreference;
use App\Filament\Resources\Jobs\Schemas\JobForm;
use App\Models\Employee;
use App\Models\Entity;
use App\Services\JobLifecycle;
use App\Services\Parsing\ParsedLead;
use App\Services\Parsing\WhatsAppLeadParser;
use App\Services\Podium\PodiumThreadReader;
use App\Support\ClosedDays;
use App\Support\GlassPosition;
use App\Support\Money;
use App\Support\PartNumberGlass;
use App\Support\Timezone;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Customer intake: paste the WhatsApp lead, pick the booking salesperson, run
 * the deterministic parser, correct anything in the editable preview, then
 * confirm to findOrCreate the customer + create the job (which queues a welcome).
 */
class Intake extends Page
{
    protected string $view = 'filament.pages.intake';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'New Lead (Intake)';

    protected static ?int $navigationSort = 0;

    public ?array $data = [];

    /** @var string[] */
    public array $warnings = [];

    public ?string $parseStatus = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('intake_leads') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'raw_text' => '',
            'is_insurance' => false,
            'needs_calibration' => false,
            'is_multi_day' => false,
            'labor_only' => false,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('1 — Paste the lead')
                    ->schema([
                        Textarea::make('raw_text')->label('WhatsApp message')->rows(10)->columnSpanFull(),
                        Actions::make([
                            Action::make('parse')
                                ->label('Parse pasted text')
                                ->icon(Heroicon::OutlinedSparkles)
                                ->action(fn () => $this->parse()),
                        ]),
                        Placeholder::make('podium_last_messages')
                            ->label('Last Podium conversation')
                            ->columnSpanFull()
                            ->content(fn (Get $get) => $this->podiumPreview($get('phone_e164'))),
                        Select::make('booking_salesperson_id')
                            ->label('Booking salesperson')
                            ->options(fn () => Employee::active()->withJobFunction(JobFunction::Sales)->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            // Required: the welcome text opens with "{salesperson}
                            // just passed your appointment over to me", so a blank
                            // here isn't a missing nicety — it's a broken sentence
                            // in the customer's first message from us.
                            ->required()
                            ->validationMessages(['required' => 'Pick the rep who booked this — their name goes in the welcome text.'])
                            ->helperText('Auto-detected from the Podium sales thread when possible — override if wrong. Named in the welcome text.'),
                    ]),
                Section::make('2 — Review & correct')
                    ->description('Parsed values are best-effort. Fix anything before confirming.')
                    ->columns(3)
                    ->schema([
                        Select::make('entity_id')->label('Entity')->options(Entity::pluck('name', 'id'))->required(),
                        TextInput::make('phone_e164')->label('Phone (E.164)')->required()->live(onBlur: true),
                        TextInput::make('first_name')->required(),
                        TextInput::make('last_name'),
                        Toggle::make('spanish_speaker')
                            ->label('Spanish speaker')
                            ->helperText('Sends this customer the Spanish version of every automated text.'),
                        Textarea::make('service_address')->label('Service address')->rows(2)->columnSpanFull()->required(),
                        TextInput::make('city')->label('City')
                            ->helperText('City only — used in the tech availability text (no address shared).'),
                        TextInput::make('vehicle_year'),
                        TextInput::make('vehicle_make'),
                        TextInput::make('vehicle_model'),
                        TextInput::make('vin')->label('VIN'),
                        Select::make('glass_position')->label('Glass position')
                            ->options(fn (Get $get) => GlassPosition::optionsFor($get('glass_position')))
                            ->searchable()
                            ->live()
                            ->helperText('Pre-filled from the parsed message ("WS" resolves to Windshield).'),
                        Toggle::make('needs_calibration')->label('Needs calibration')
                            ->helperText(fn (Get $get) => self::calibrationForced($get)
                                ? 'Insurance windshields always need calibration.'
                                : 'Tick if this windshield needs ADAS calibration.')
                            ->disabled(fn (Get $get) => self::calibrationForced($get)),
                        // Customer already has the glass — we only install it. The
                        // whole parts side (order, prepay, warehouse, part cost)
                        // drops away; everything else runs as normal.
                        Toggle::make('labor_only')
                            ->label('Labor only (customer has the part)')
                            ->columnSpanFull()
                            ->live()
                            ->helperText('The customer supplies the glass - we just install it. No part to order, prepay, source or cost out.'),
                        Repeater::make('parts')
                            ->label('Parts')
                            ->columnSpanFull()
                            ->addActionLabel('Add part')
                            ->defaultItems(1)
                            ->columns(3)
                            ->live()
                            ->visible(fn (Get $get) => ! $get('labor_only'))
                            ->schema([
                                TextInput::make('label')->label('Part # / description')->columnSpan(2)->live(onBlur: true),
                                // Optional at intake: we usually don't know the cost
                                // until we actually order the part. It's collected
                                // (and required) in the "Order part" modal instead.
                                TextInput::make('cost')->label('Cost ($)')->numeric()->prefix('$')->live(onBlur: true)
                                    ->helperText('Optional - entered when the part is ordered.'),
                            ]),
                        // Advisory only, and deliberately so: the 2nd letter of a
                        // NAGS part number says which glass it is, so an "FW…"
                        // windshield part on a door-glass lead means somebody
                        // wrote something down wrong. Intake must NOT stop for
                        // it — the welcome/scheduling texts still need to go out
                        // while sales is asked what the customer actually needs.
                        // Scheduling is where it becomes blocking (see
                        // JobActions::partGlassSection).
                        Placeholder::make('part_glass_warning')
                            ->label('')
                            ->columnSpanFull()
                            ->visible(fn (Get $get) => self::partGlassMismatch($get) !== null)
                            ->content(fn (Get $get) => self::partGlassWarningHtml($get)),
                        Placeholder::make('parts_total')
                            ->label('Parts total')
                            ->visible(fn (Get $get) => ! $get('labor_only'))
                            ->content(fn (Get $get) => Money::format(self::sumPartsCents($get('parts') ?? []))),
                        TextInput::make('quote')->label('Quote ($)')->numeric()->prefix('$')
                            ->helperText(fn (Get $get) => $get('is_insurance')
                                ? 'Insurance job — this is the deductible the customer pays.'
                                : null),
                        Toggle::make('is_insurance')->label('Insurance job')->live()
                            ->helperText('Customer pays the deductible (the quote); the insurer pays the balance later.'),
                        Toggle::make('is_multi_day')->label('Multi-day install')
                            ->helperText('Takes two visits. Marking the first day complete prompts for the second date instead of finishing the job.'),
                        DatePicker::make('requested_day')->label('Requested day')
                            ->native(false)
                            ->displayFormat('D, M j, Y')
                            ->closeOnDateSelection()
                            ->minDate(fn () => Timezone::now()->startOfDay())
                            // We're closed Sundays and on tagged holidays, so a
                            // customer can't have asked for one.
                            ->disabledDates(fn () => ClosedDays::upcoming())
                            ->helperText('Pre-filled from the parsed message ("today"/"tomorrow"/a weekday resolve to a real date). Sundays and holidays are not selectable.'),
                        ...JobForm::requestedTimeFields(),
                        Textarea::make('notes')->label('Notes')
                            ->rows(3)
                            ->columnSpanFull()
                            ->helperText('Anything dispatch/the tech should know. Saved on the job.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function parse(): void
    {
        $raw = (string) ($this->data['raw_text'] ?? '');
        if (trim($raw) === '') {
            Notification::make()->title('Paste a message first')->warning()->send();

            return;
        }

        $lead = app(WhatsAppLeadParser::class)->parse($raw);
        $this->warnings = $lead->warnings;
        $this->parseStatus = $lead->status()->value;

        // Resolve entity code -> id when the parser confidently recognized one.
        $entityId = $lead->entity_code
            ? Entity::where('code', $lead->entity_code)->value('id')
            : null;

        // Keep a manually-chosen salesperson; otherwise try to detect the rep from
        // how they signed the Podium sales thread ("Marcel here", "This is Marcel").
        $salespersonId = $this->data['booking_salesperson_id'] ?? null;
        $salespersonAutoDetected = false;
        if ($salespersonId === null && $lead->phone_e164 !== null) {
            $salespersonId = $this->detectSalespersonId(
                $this->podiumMessagesFor($lead->phone_e164),
                $lead->first_name,
            );
            $salespersonAutoDetected = $salespersonId !== null;
        }

        $this->form->fill([
            'raw_text' => $raw,
            'booking_salesperson_id' => $salespersonId,
            'entity_id' => $entityId,
            'phone_e164' => $lead->phone_e164,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'service_address' => $this->mergeLocation($lead->service_address, $lead->city),
            // City is auto-derived from the service address by the parser when
            // the rep didn't send an explicit "City:" line.
            'city' => $lead->city,
            'vehicle_year' => $lead->vehicle_year,
            'vehicle_make' => $lead->vehicle_make,
            'vehicle_model' => $lead->vehicle_model,
            'vin' => $lead->vin,
            'glass_position' => $lead->glass_position,
            'parts' => $this->partsToForm($lead->parts),
            'quote' => $lead->quote_amount_cents !== null ? $lead->quote_amount_cents / 100 : null,
            // The parser doesn't detect insurance; preserve whatever the operator toggled.
            'is_insurance' => $this->data['is_insurance'] ?? false,
            // Same for the calibration flag — parser doesn't set it.
            'needs_calibration' => $this->data['needs_calibration'] ?? false,
            // Same for multi-day — the rep tells us, the parser can't.
            'is_multi_day' => $this->data['is_multi_day'] ?? false,
            // The picker takes a real date — the parser resolves "today" /
            // "tomorrow" / a weekday / "7/21" into one (null when it couldn't).
            // We're closed Sundays/holidays and the picker refuses to select
            // one, so a parsed date landing there is pulled to the next open
            // day (warned about below) rather than handed over unselectable.
            'requested_day' => $this->openRequestedDate($lead),
            // The Select shows the structured preference; the free text keeps
            // only the specific clock time (the Select's other cases carry
            // their own wording, so re-showing "Morning" there would be noise).
            'requested_time_preference' => $lead->requested_time_preference ?? TimePreference::None->value,
            'requested_time' => $lead->requested_time_preference === TimePreference::Specific->value
                ? $lead->requested_time
                : null,
            // Same idea for "after 10am": the hour is structured, the case's
            // own wording carries the rest.
            'requested_after_time' => $lead->requested_after_time,
            // Parsed "Notes:" wins; otherwise keep anything typed before parsing.
            'notes' => $lead->notes ?? ($this->data['notes'] ?? null),
        ]);

        // Stash the structured payload for persistence on confirm.
        $this->data['_payload'] = $lead->toPayload();

        $body = null;
        if ($salespersonAutoDetected) {
            $body = 'Booking salesperson auto-detected: '
                .(Employee::find($salespersonId)?->name ?? 'unknown').'. Override if wrong.';
        }

        Notification::make()
            ->title('Parsed — '.count($this->warnings).' warning(s)')
            ->body($body)
            ->status($this->warnings ? 'warning' : 'success')
            ->send();
    }

    /**
     * The parsed requested date, moved off a Sunday/holiday onto the next day
     * we're open. Adds a parse warning when it moves, so the operator sees the
     * date they're looking at isn't the one the message asked for.
     */
    private function openRequestedDate(ParsedLead $lead): ?string
    {
        if ($lead->requested_date === null) {
            return null;
        }

        $open = ClosedDays::nextOpen($lead->requested_date);
        if ($open !== $lead->requested_date) {
            $this->warnings[] = "Requested day {$lead->requested_date} falls on a Sunday/holiday - moved to {$open}.";
        }

        return $open;
    }

    /**
     * Best-effort guess of the booking salesperson from the Podium sales thread.
     * Reps sign their texts ("Marcel here from …", "This is Marcel again"), so we
     * count whole-word first-name mentions of each active sales employee across the
     * OUTBOUND (sales-side) messages and pick the most-mentioned. The customer's
     * own first name is excluded so "Hello Susan!" can't match a rep named Susan.
     * Returns null when nothing matches — the operator then picks manually.
     *
     * @param  array<int, array{direction: string, body: ?string, at: ?string}>  $messages
     */
    private function detectSalespersonId(array $messages, ?string $customerFirstName): ?int
    {
        $text = collect($messages)
            ->filter(fn (array $m) => ($m['direction'] ?? 'inbound') === 'outbound')
            ->map(fn (array $m) => (string) ($m['body'] ?? ''))
            ->implode("\n");

        if (trim($text) === '') {
            return null;
        }

        $customerFirst = strtolower(trim((string) $customerFirstName));

        $bestId = null;
        $bestCount = 0;
        $sales = Employee::active()->withJobFunction(JobFunction::Sales)->get(['id', 'name']);
        foreach ($sales as $employee) {
            $first = strtolower(trim((string) (explode(' ', trim($employee->name))[0] ?? '')));
            if ($first === '' || $first === $customerFirst) {
                continue;
            }

            $count = preg_match_all('/\b'.preg_quote($first, '/').'\b/i', $text);
            if ($count > $bestCount) {
                $bestCount = $count;
                $bestId = $employee->id;
            }
        }

        return $bestId;
    }

    public function create(): void
    {
        $data = $this->form->getState();

        $lead = app(WhatsAppLeadParser::class)->parse((string) ($data['raw_text'] ?? ''));

        // Labor only = the customer's own glass, so nothing parts-related is
        // stored even if the repeater had been filled in before the toggle flipped.
        $laborOnly = (bool) ($data['labor_only'] ?? false);
        $parts = $laborOnly ? [] : $this->partsFromForm($data['parts'] ?? []);
        $partCosts = array_filter(array_column($parts, 'cost_cents'), fn ($c) => $c !== null);
        $partTotalCents = $partCosts === [] ? null : array_sum($partCosts);

        // Requested appointment: the parser's best guess at day + time. Our team
        // adjusts these before saving when the parse missed; the effective string
        // is just "<day> <time>".
        $reqDay = $this->nullIfBlank($data['requested_day'] ?? null);
        $reqPreference = TimePreference::tryFrom((string) ($data['requested_time_preference'] ?? ''))
            ?? TimePreference::None;
        // Only a "specific" preference carries a free-text time; the others are
        // fully described by the case itself.
        $reqTime = $reqPreference === TimePreference::Specific
            ? $this->nullIfBlank($data['requested_time'] ?? null)
            : null;
        // "After" carries a structured hour instead of free text.
        $reqAfterTime = $reqPreference === TimePreference::After
            ? $this->nullIfBlank($data['requested_after_time'] ?? null)
            : null;
        // The day is now a real date (Y-m-d) — spell it out for the SMS templates
        // ("Monday, July 21 at 2pm"). If the picker was left empty (parser missed
        // and nobody set it), fall back to what the message literally said.
        $reqEffective = $this->nullIfBlank(trim(
            (string) JobForm::formatRequestedDay($reqDay).' '
            .trim((string) ($reqTime ?? $reqPreference->shortLabel($reqAfterTime)))
        )) ?? $lead->requested_daytime_raw;

        $job = app(JobLifecycle::class)->createFromIntake([
            'entity_id' => $data['entity_id'],
            'phone_e164' => $data['phone_e164'],
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'service_address' => $data['service_address'] ?? null,
            'city' => $this->nullIfBlank($data['city'] ?? null),
            // Only ever SETS Spanish — leaving the toggle off never resets a
            // known Spanish speaker back to English (see findOrCreateCustomer).
            'preferred_language' => ($data['spanish_speaker'] ?? false) ? Language::Spanish->value : null,
            'vehicle_year' => $data['vehicle_year'] ?? null,
            'vehicle_make' => $data['vehicle_make'] ?? null,
            'vehicle_model' => $data['vehicle_model'] ?? null,
            'vin' => $data['vin'] ?? null,
            'glass_position' => $data['glass_position'] ?? null,
            'vehicle_raw' => $lead->vehicle_raw,
            'part_number' => $parts[0]['label'] ?? null,
            'parts_json' => $parts === [] ? null : $parts,
            'part_cost_cents' => $partTotalCents,
            'labor_only' => $laborOnly,
            'quote_amount_cents' => Money::parseToCents((string) ($data['quote'] ?? '')),
            'is_insurance' => (bool) ($data['is_insurance'] ?? false),
            // Insurance always needs calibration (even when the disabled toggle
            // doesn't dehydrate true) - but only on a WINDSHIELD. Manual flag
            // still applies for cash jobs.
            'needs_calibration' => (bool) ((($data['is_insurance'] ?? false)
                && GlassPosition::isWindshield($data['glass_position'] ?? null))
                || ($data['needs_calibration'] ?? false)),
            'is_multi_day' => (bool) ($data['is_multi_day'] ?? false),
            'booking_salesperson_id' => $data['booking_salesperson_id'] ?? null,
            'requested_daytime_raw' => $reqEffective,
            'requested_day' => $reqDay,
            'requested_time' => $reqTime,
            'requested_time_preference' => $reqPreference->value,
            'requested_after_time' => $reqAfterTime,
            'intake_raw_text' => $data['raw_text'] ?? null,
            'notes' => $this->nullIfBlank($data['notes'] ?? null),
            'parsed_payload_json' => $data['_payload'] ?? $lead->toPayload(),
            'parse_status' => $lead->status()->value,
            'parse_warnings_json' => $lead->warnings,
        ], auth()->user());

        Notification::make()->title("Job {$job->job_number} created")->success()->send();

        // Carried through the redirect so the reminder to chase sales doesn't
        // vanish with the intake form. The job is already created — this never
        // blocks anything.
        if (($mismatch = $job->partGlassMismatch()) !== null) {
            Notification::make()
                ->title('Check the part number with the sales team')
                ->body(PartNumberGlass::sentence($mismatch)
                    .' Ask sales to clarify. The job is created and its texts will go out; it has to be sorted out before it can be scheduled.')
                ->warning()
                ->persistent()
                ->send();
        }

        // Next step: dispatcher picks which welcome to send before the job view.
        $this->redirect(SendWelcome::getUrl(['job' => $job->id]));
    }

    /**
     * Combine the parser's separate city + street-address into the single
     * service-address field the form now exposes. Prefers the street address;
     * appends the city only when it isn't already contained in it (so
     * "14163 Saddletree Ct, Sylmar…" + "Sylmar" stays just the address).
     */
    private function mergeLocation(?string $address, ?string $city): ?string
    {
        $address = trim((string) $address);
        $city = trim((string) $city);

        if ($address === '') {
            return $city === '' ? null : $city;
        }
        if ($city === '' || stripos($address, $city) !== false) {
            return $address;
        }

        return $address.', '.$city;
    }

    /** Trim a value to null when it's empty/whitespace. */
    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Render the most recent Podium messages for the entered phone so the
     * operator can see how the sales conversation ended and craft the first
     * scheduling text. Empty/normalizing phones show a friendly hint.
     */
    private function podiumPreview(?string $phone): HtmlString
    {
        $messages = $this->podiumMessagesFor($phone);

        if ($messages === []) {
            return new HtmlString(
                '<span class="text-sm text-gray-500">No Podium conversation found for this number.</span>'
            );
        }

        $tz = config('glass.timezone');

        // Oldest -> newest reads like a transcript.
        $rows = collect($messages)->map(function (array $m) use ($tz) {
            $who = ($m['direction'] ?? 'inbound') === 'outbound' ? 'Sales' : 'Customer';
            $when = $m['at'] ? Carbon::parse($m['at'])->timezone($tz)->format('M j, g:ia') : '';
            $body = e($m['body'] ?? '');

            return '<div class="mb-1"><span class="font-semibold">'.$who.'</span> '
                .'<span class="text-xs text-gray-500">'.$when.'</span><br>'
                .'<span class="text-sm">'.$body.'</span></div>';
        })->implode('');

        return new HtmlString($rows);
    }

    /**
     * Last few Podium messages for a phone, oldest->newest.
     *
     * @return array<int, array{direction: string, body: ?string, at: ?string}>
     */
    private function podiumMessagesFor(?string $phone): array
    {
        return app(PodiumThreadReader::class)->latestFor($phone, 5);
    }

    /**
     * Parser parts ([{label, cost_cents}]) -> repeater rows ([{label, cost$}]).
     *
     * @param  array<int, array{label: ?string, cost_cents: ?int}>  $parts
     */
    private function partsToForm(array $parts): array
    {
        $rows = array_map(fn ($p) => [
            'label' => $p['label'] ?? null,
            'cost' => isset($p['cost_cents']) && $p['cost_cents'] !== null ? $p['cost_cents'] / 100 : null,
        ], $parts);

        // Always leave the operator at least one empty row to fill.
        return $rows === [] ? [['label' => null, 'cost' => null]] : $rows;
    }

    /** Live total (in cents) of repeater rows whose cost ($) is filled in. */
    /**
     * Calibration is auto-forced only on an INSURANCE + WINDSHIELD job — any
     * other glass leaves the toggle free (mirrors Job::needsCalibration()).
     */
    private static function calibrationForced(Get $get): bool
    {
        return (bool) $get('is_insurance')
            && GlassPosition::isWindshield($get('glass_position'));
    }

    /**
     * The live part-number vs glass-position disagreement, read straight off the
     * form (not the job — nothing is saved yet). Null on a labor-only job: the
     * customer brings their own glass, so there is no part number of ours.
     */
    private static function partGlassMismatch(Get $get): ?array
    {
        if ($get('labor_only')) {
            return null;
        }

        $labels = array_map(fn ($row) => $row['label'] ?? null, (array) ($get('parts') ?? []));

        return PartNumberGlass::mismatch($labels, $get('glass_position'));
    }

    /** The amber "check this with sales, then keep going" banner under the parts. */
    private static function partGlassWarningHtml(Get $get): HtmlString
    {
        $mismatch = self::partGlassMismatch($get);

        if ($mismatch === null) {
            return new HtmlString('');
        }

        return new HtmlString(
            '<div style="border:1px solid #f59e0b55;background:#f59e0b14;border-radius:.5rem;padding:.75rem 1rem;">'
            .'<div style="font-weight:600;color:#b45309;">Part number and glass position do not match</div>'
            .'<div style="margin-top:.25rem;font-size:.875rem;color:#92400e;">'
            .e(PartNumberGlass::sentence($mismatch))
            .' Please alert the sales team and ask them to clarify - then carry on and create the job.'
            .' You will be asked to sort it out before this job can be scheduled.</div>'
            .'</div>'
        );
    }

    private static function sumPartsCents(array $rows): int
    {
        $total = 0;
        foreach ($rows as $row) {
            $cents = Money::parseToCents((string) ($row['cost'] ?? ''));
            if ($cents !== null) {
                $total += $cents;
            }
        }

        return $total;
    }

    /**
     * Repeater rows ([{label, cost$}]) -> storage parts ([{label, cost_cents}]),
     * dropping rows the operator left entirely blank.
     *
     * @return array<int, array{label: ?string, cost_cents: ?int}>
     */
    private function partsFromForm(array $rows): array
    {
        $parts = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $cost = Money::parseToCents((string) ($row['cost'] ?? ''));
            if ($label === '' && $cost === null) {
                continue;
            }
            $parts[] = ['label' => $label === '' ? null : $label, 'cost_cents' => $cost];
        }

        return $parts;
    }
}
