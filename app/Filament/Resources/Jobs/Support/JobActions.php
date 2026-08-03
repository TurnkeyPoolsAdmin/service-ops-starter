<?php

namespace App\Filament\Resources\Jobs\Support;

use App\Enums\Audience;
use App\Enums\JobStatus;
use App\Enums\Language;
use App\Enums\PaymentMethod;
use App\Enums\TemplateKey;
use App\Enums\TimePreference;
use App\Filament\Pages\AiScheduling;
use App\Filament\Pages\PartsToOrder;
use App\Filament\Resources\Jobs\JobResource;
use App\Filament\Resources\Jobs\Schemas\JobForm;
use App\Filament\Resources\ReviewRequests\ReviewRequestResource;
use App\Models\AiSchedulingSession;
use App\Models\Employee;
use App\Models\Job;
use App\Models\JobNote;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\AiScheduling\AiSchedulingService;
use App\Services\AiScheduling\RequestedSlotParser;
use App\Services\AiScheduling\SchedulingConstraint;
use App\Services\JobLifecycle;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Parts\PartsOrderLinks;
use App\Services\Receipts\ReceiptPdfService;
use App\Services\Stripe\StripeClient;
use App\Services\Warranty\WarrantyService;
use App\Support\GlassPosition;
use App\Support\Money;
use App\Support\PartNumberGlass;
use App\Support\SchedulingAsk;
use App\Support\Timezone;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Illuminate\Support\HtmlString;
use Livewire\Component;

/**
 * Factory for the job quick-actions. Each routes through JobLifecycle so side
 * effects (outbox rows, activity log, recorded_by, completion) fire exactly once.
 */
class JobActions
{
    private static function lifecycle(): JobLifecycle
    {
        return app(JobLifecycle::class);
    }

    private static function actor(): Employee
    {
        return auth()->user();
    }

    private static function technicianOptions(): array
    {
        return Employee::active()->withJobFunction('technician')->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function schedule(): Action
    {
        return Action::make('schedule')
            ->label('Schedule')
            ->icon('heroicon-o-calendar-days')
            // Only for jobs not yet scheduled — once scheduled, use Reschedule.
            ->visible(fn (Job $record) => $record->status === JobStatus::PendingSchedule)
            ->fillForm(fn (Job $record) => self::scheduleFormDefaults($record))
            ->schema(fn (Job $record) => self::scheduleFields($record))
            ->action(function (Job $record, array $data, Component $livewire) {
                self::resolvePartGlassMismatch($record, $data);
                self::applySchedule($record, $data);
                self::confirmSameDayOrderIfChecked($record, $data);
                self::notifyScheduled($record, 'Job scheduled', $livewire);
            });
    }

    /**
     * Change the time/date (and phrasing/tech/warehouse) of an already-scheduled
     * job. Same fields as Schedule, plus a toggle to text the customer about the
     * new time. The reschedule text only sends when the appointment instant
     * actually changed; reminders are always rebuilt for the new time.
     */
    public static function reschedule(): Action
    {
        return Action::make('reschedule')
            ->label('Reschedule')
            ->icon('heroicon-o-calendar')
            ->color('warning')
            ->visible(fn (Job $record) => $record->status === JobStatus::Scheduled)
            ->fillForm(fn (Job $record) => self::scheduleFormDefaults($record) + ['notify_customer' => true])
            ->schema(fn (Job $record) => [
                ...self::scheduleFields($record),
                Toggle::make('notify_customer')
                    ->label('Text the customer about the new time')
                    ->helperText('Sends the "appointment rescheduled" text. Reminders are rebuilt for the new time either way.')
                    ->default(true),
            ])
            ->action(function (Job $record, array $data, Component $livewire) {
                $before = $record->scheduled_at;
                self::resolvePartGlassMismatch($record, $data);
                self::applySchedule($record, $data);
                self::confirmSameDayOrderIfChecked($record, $data);
                $timeChanged = $before === null || ! $before->equalTo($record->scheduled_at);

                $notified = ($data['notify_customer'] ?? false) && $timeChanged;
                if ($notified) {
                    self::lifecycle()->sendRescheduleNotice($record, self::actor());
                }

                self::notifyScheduled($record, $notified ? 'Rescheduled — customer notified' : 'Job rescheduled', $livewire);
            });
    }

    /**
     * "Cancel appointment" — the customer needs to reschedule but has no new time
     * yet. Pulls the job off the calendar back into the scheduling queue (keeps it
     * open, keeps the tech), texts the customer we'll follow up, texts the tech the
     * job came off their schedule, and — if a part was already ordered — prompts the
     * team to call the vendor and cancel that order.
     */
    public static function cancelAppointment(): Action
    {
        return Action::make('cancelAppointment')
            ->label('Cancel appointment')
            ->icon('heroicon-o-calendar-days')
            ->color('danger')
            ->modalHeading('Cancel this appointment?')
            ->modalDescription('Use when the customer needs to reschedule but has no new time yet. The job moves back to the scheduling queue (off the calendar), the customer is texted that we\'ll follow up with a new time, and the assigned tech is notified. To close a job that fell through for good, use "Mark lost" instead.')
            ->modalSubmitActionLabel('Cancel appointment')
            ->visible(fn (Job $record) => $record->status === JobStatus::Scheduled)
            ->schema([
                TextInput::make('reason')->label('Reason (optional)')
                    ->placeholder('Customer needs to reschedule, etc.'),
                Radio::make('part_picked_up')
                    ->label('Was a part picked up?')
                    ->helperText('If yes, the tech is texted to return the part and it moves to Parts → "Parts returned".')
                    // Labor only — the glass is the customer's, never ours to return.
                    ->visible(fn (Job $record) => ! $record->isLaborOnly())
                    ->boolean()
                    ->default(false)
                    ->inline()
                    ->inlineLabel(false),
            ])
            ->action(function (Job $record, array $data) {
                $job = self::lifecycle()->cancelAppointment(
                    $record,
                    $data['reason'] ?? null,
                    self::actor(),
                    (bool) ($data['part_picked_up'] ?? false),
                );

                if ($job->getAttribute('wasPartsOrdered')) {
                    Notification::make()
                        ->title('Appointment canceled — call to cancel the part order')
                        ->body('A part was already ordered for this job. Call the supplier to cancel that order.')
                        ->warning()
                        ->persistent()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Appointment canceled — moved back to scheduling')
                        ->success()->send();
                }

                if ($job->needsPartReturn()) {
                    Notification::make()
                        ->title('Part return requested')
                        ->body('The tech was texted to return the part. It\'s now on Parts → "Parts returned" until it\'s brought back.')
                        ->warning()
                        ->send();
                }
            });
    }

    /**
     * If dispatch ticked "I've ordered the part" in the same-day section, stamp
     * it — the checkbox is NOT required (2026-07-23: same-day scheduling no
     * longer blocks on ordering; see sameDayOrderSection()), so this is best-
     * effort, not a guarantee. The virtual fields never reach applySchedule/
     * JobLifecycle::schedule, so they're handled here instead. Ticking the
     * checkbox DOES require a part cost in the same submit (2026-07-24) — the
     * cost fields only render/validate once the checkbox is live-toggled on.
     */
    private static function confirmSameDayOrderIfChecked(Job $record, array $data): void
    {
        if ($data['parts_ordered_confirm'] ?? false) {
            self::applyPartsCost($record, $data['parts_cost'] ?? []);
            self::lifecycle()->markPartsOrdered($record, self::actor());
        }
    }

    /**
     * After a successful schedule/reschedule: if the part is already ordered
     * (same-day jobs stamp it at submit time), just show the success
     * notification. Otherwise chain straight into the shared "Order part"
     * modal so dispatch orders it (or dismisses — the job still surfaces on
     * the Parts to Order page either way). Falls back to a persistent
     * notification with direct order links if the modal can't be chained.
     */
    private static function notifyScheduled(Job $record, string $successTitle, $livewire): void
    {
        Notification::make()->title($successTitle)->success()->send();

        // Labor-only: the customer brings the glass, so don't chase an order.
        if ($record->isLaborOnly() || $record->isPartsOrdered()) {
            return;
        }

        if (method_exists($livewire, 'replaceMountedAction')) {
            $livewire->replaceMountedAction('orderParts');

            return;
        }

        $local = Timezone::toDisplay($record->scheduled_at);
        if ($local !== null) {
            self::sendOrderPartsPrompt($record, $successTitle, $local);
        }
    }

    /** Persistent "order the part now" notification with direct order-link buttons. */
    private static function sendOrderPartsPrompt(Job $record, string $successTitle, Carbon $local): void
    {
        $links = app(PartsOrderLinks::class)->linksForJob($record);
        $customer = $record->customer_name_snapshot ?: 'the customer';
        $partsLabel = collect($links)->pluck('part')->filter()->implode(', ') ?: 'the part';

        $actions = [];
        $hasDirectLink = false;
        foreach ($links as $link) {
            if ($link['url'] !== null) {
                $hasDirectLink = true;
                $actions[] = Action::make('order_'.md5($link['url']))
                    ->label('Order '.($link['part'] ?? 'part'))
                    ->url($link['url'], shouldOpenInNewTab: true)
                    ->button();
            }
        }

        if (! $hasDirectLink) {
            $actions[] = Action::make('pick_warehouse')
                ->label('Pick warehouse & order')
                ->url(PartsToOrder::getUrl())
                ->button();
        }

        $actions[] = Action::make('open_parts_to_order')
            ->label('Open Parts')
            ->url(PartsToOrder::getUrl())
            ->button();

        Notification::make()
            ->title($successTitle.' — order the part now')
            ->body($customer.' — '.$partsLabel.' — '.$local->format('D, M j g:ia')
                .($hasDirectLink ? '' : '. Select a warehouse to get the order link.'))
            ->warning()
            ->persistent()
            ->actions($actions)
            ->send();
    }

    /** Shared date/time/phrasing/tech/warehouse fields for schedule + reschedule. */
    private static function scheduleFields(Job $record): array
    {
        return [
            DatePicker::make('scheduled_date')
                ->label('Appointment date (Pacific)')
                ->native(false)
                ->required()
                ->live(),
            TimePicker::make('scheduled_time')
                ->label('Appointment time (Pacific)')
                ->seconds(false)
                ->required(),
            Radio::make('time_phrasing')
                ->label('Customer text says')
                ->options([
                    'window' => '2-hour window',
                    'custom_window' => 'Different time window',
                    'exact' => 'Exact time',
                ])
                ->descriptions([
                    'window' => 'The default — e.g. "between 8am–10am".',
                    'custom_window' => 'A wider 1-, 3-, or 4-hour window - e.g. "between 8am-11am".',
                    'exact' => 'Promises the exact time — e.g. "at 8am".',
                ])
                ->default('window')
                ->required()
                ->live(),
            Select::make('window_hours')
                ->label('Window length')
                ->options([1 => '1 hour (e.g. 8am-9am)', 3 => '3 hours (e.g. 8am-11am)', 4 => '4 hours (e.g. 8am-12pm)'])
                ->visible(fn ($get) => $get('time_phrasing') === 'custom_window')
                ->required(fn ($get) => $get('time_phrasing') === 'custom_window'),
            Select::make('technician_id')->label('Technician')->options(self::technicianOptions())->searchable()
                ->required()->helperText('A technician must be assigned to schedule the job.'),
            // Not required — the warehouse is often not known yet at schedule
            // time, and scheduling must not wait on it (the time-confirmation
            // text goes out the moment date/time/tech are set). The "Order
            // part" action re-asks for a warehouse whenever the job still has
            // none (JobActions::needsWarehouse).
            ...JobForm::warehouseFields(),
            self::partGlassSection($record),
            self::sameDayOrderSection($record),
        ];
    }

    /**
     * The second — and blocking — half of the part-number vs glass-position
     * check. Intake only warns (see App\Filament\Pages\Intake): the lead still
     * has to get its welcome texts out while sales is asked what the customer
     * actually needs. By the time we put it on the calendar the answer is due,
     * so this section stands between dispatch and the Schedule button.
     *
     * Two ways past it, and only two:
     *  - RESOLVE — correct the glass position and/or the part number right here
     *    (both fields are ->live(), so the banner re-reads them as you type and
     *    the override disappears the moment they agree).
     *  - OVERRIDE — tick the box and write down why. That schedules the job as-is
     *    AND raises a super-admin Issue for a manager to review the call.
     *
     * Renders only for a job that currently has an UNRESOLVED mismatch, so a
     * clean job's schedule modal is untouched.
     */
    private static function partGlassSection(Job $record): Section
    {
        $stillOff = fn ($get) => PartNumberGlass::disagree($get('part_glass_number'), $get('part_glass_position'));

        return Section::make('Part number does not match the glass position')
            ->description('This has to be sorted out before the job goes on the calendar. Fix whichever value is wrong, or override with a reason - an override goes to a manager for review.')
            ->visible(fn () => $record->hasUnresolvedPartGlassMismatch())
            ->schema([
                Placeholder::make('part_glass_explainer')->label('')
                    ->content(fn ($get) => self::partGlassExplainer($get)),
                Select::make('part_glass_position')->label('Glass position')
                    ->options(fn ($get) => GlassPosition::optionsFor($get('part_glass_position')))
                    ->searchable()
                    ->required()
                    ->live()
                    ->default($record->glass_position),
                TextInput::make('part_glass_number')->label('Part # (primary)')
                    ->live(onBlur: true)
                    ->default($record->partLabels()[0] ?? null)
                    ->helperText('The 2nd letter is the glass: W windshield, D door, V vent, Q quarter, B back, R roof.'),
                Checkbox::make('part_glass_override')
                    ->label('Override - schedule anyway')
                    ->visible($stillOff)
                    ->live()
                    ->required($stillOff)
                    ->accepted($stillOff)
                    ->validationMessages([
                        'accepted' => 'Fix the part number or the glass position, or tick the override and say why.',
                        'required' => 'Fix the part number or the glass position, or tick the override and say why.',
                    ])
                    ->helperText('Flags the job on the Issues queue for a manager to review.'),
                Textarea::make('part_glass_override_note')
                    ->label('Why are you overriding?')
                    ->rows(2)
                    ->visible(fn ($get) => $stillOff($get) && $get('part_glass_override'))
                    ->required(fn ($get) => $stillOff($get) && $get('part_glass_override'))
                    ->placeholder('e.g. Confirmed with the customer - the part number is right, the position was typed wrong on the lead.'),
            ]);
    }

    /** Live read of the two fields above: still off, or now in agreement. */
    private static function partGlassExplainer($get): HtmlString
    {
        $mismatch = PartNumberGlass::mismatch([$get('part_glass_number')], $get('part_glass_position'));

        if ($mismatch === null) {
            return new HtmlString(
                '<span style="color:#15803d;font-weight:600;">These agree now - go ahead and schedule.</span>'
            );
        }

        return new HtmlString(
            '<span style="color:#b45309;">'.e(PartNumberGlass::sentence($mismatch)).'</span>'
        );
    }

    /**
     * Apply whatever the part/glass section came back with, BEFORE scheduling:
     * write any correction to the job, then stamp an override if one was needed.
     * Both calls are no-ops when the section never rendered, so an ordinary
     * schedule/reschedule submit passes straight through.
     */
    private static function resolvePartGlassMismatch(Job $record, array $data): void
    {
        if (! array_key_exists('part_glass_position', $data) && ! array_key_exists('part_glass_number', $data)) {
            return;
        }

        self::lifecycle()->correctPartGlass(
            $record,
            $data['part_glass_position'] ?? null,
            $data['part_glass_number'] ?? null,
            self::actor(),
        );

        if (($data['part_glass_override'] ?? false) && filled($data['part_glass_override_note'] ?? null)) {
            self::lifecycle()->overridePartGlassMismatch($record, trim((string) $data['part_glass_override_note']), self::actor());

            Notification::make()
                ->title('Scheduled on an override - flagged for a manager')
                ->body('The part number and glass position still disagree. A manager will see this on the Issues queue.')
                ->warning()
                ->persistent()
                ->send();
        }
    }

    /**
     * Non-blocking "order the part" warning shown only when the picked date is
     * today (Pacific) and the job isn't already ordered. Scheduling proceeds
     * either way — leaving the checkbox unchecked no longer holds up the
     * appointment/texts (2026-07-23: previously required + accepted()). A
     * Telegram reminder (alerts:telegram-same-day-parts-unordered, every 30
     * min) keeps nagging the group chat until the part is marked ordered.
     *
     * Ticking "I've ordered the part" DOES require a cost in the same submit
     * (2026-07-24) — the checkbox is ->live() so partsCostFields() (shared
     * with the Order-part modal) can render + require itself only once it's
     * checked, keeping the checkbox itself non-blocking either way.
     */
    private static function sameDayOrderSection(Job $record): Section
    {
        $isSameDay = fn ($get) => filled($get('scheduled_date'))
            && $get('scheduled_date') === Timezone::now()->format('Y-m-d')
            && ! $record->isLaborOnly()
            && ! $record->isPartsOrdered();

        return Section::make('Order the part')
            ->description('This job is today — please order the part ASAP. A Telegram reminder will repeat every 30 minutes until it\'s marked ordered.')
            ->visible($isSameDay)
            ->schema([
                Placeholder::make('same_day_links')->label('')
                    ->content(fn ($get) => self::orderLinksHtml($record, $get)),
                Checkbox::make('parts_ordered_confirm')
                    ->label("I've ordered the part")
                    ->visible($isSameDay)
                    ->live(),
                ...self::partsCostFields('parts_ordered_confirm'),
            ]);
    }

    /**
     * "Schedule w/ AI" — starts a negotiation session instead of picking a
     * time by hand. See docs/ai-scheduling-spec.md. Visible only once the
     * global switch is on, the job is unscheduled, no session is already
     * live for it, and at least one tech at this location has opted in.
     */
    public static function scheduleWithAi(): Action
    {
        return Action::make('scheduleWithAi')
            ->label('Schedule w/ AI')
            ->icon('heroicon-o-sparkles')
            ->color('info')
            ->visible(fn (Job $record) => AiSchedulingService::enabled()
                && $record->status === JobStatus::PendingSchedule
                && AiSchedulingService::liveSessionFor($record) === null
                && self::hasAiOptedInTechs($record))
            ->modalHeading('Schedule with AI')
            ->modalDescription('Texts technicians one at a time proposing a specific slot and negotiates a time over SMS. Watch it work (or step in) from the AI Scheduling page.')
            ->modalSubmitActionLabel('Start')
            ->schema(fn (Job $record) => self::scheduleWithAiFields($record))
            ->action(function (Job $record, array $data) {
                // The AI session books through JobLifecycle::schedule too, so it
                // gets the same gate as the manual modal — otherwise a mismatch
                // could reach the calendar without anyone answering for it.
                self::resolvePartGlassMismatch($record, $data);

                $constraint = self::constraintFromAiForm($data);
                $warehouseId = ($data['warehouse_id'] ?? null) === JobForm::WAREHOUSE_OTHER ? null : ($data['warehouse_id'] ?? null);

                $session = app(AiSchedulingService::class)->start(
                    $record,
                    $constraint,
                    self::actor(),
                    techMode: $data['tech_mode'] ?? AiSchedulingSession::MODE_SIMULATE,
                    customerMode: $data['customer_mode'] ?? AiSchedulingSession::MODE_OFF,
                    warehouseId: $warehouseId,
                    warehouseOther: $data['warehouse_other'] ?? null,
                    techOrder: self::techOrderFromAiForm($data),
                );

                if ($session === null) {
                    Notification::make()
                        ->title('This job already has a live AI scheduling session')
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('AI scheduling started')
                    ->body('Targeting '.$constraint->phrase(Language::English).'. Watch progress on the AI Scheduling page.')
                    ->success()
                    ->send();

                return redirect(AiScheduling::getUrl(['selected' => $session->id]));
            });
    }

    private static function hasAiOptedInTechs(Job $record): bool
    {
        $poolIds = $record->entity?->techPoolEntityIds() ?? array_filter([$record->entity_id]);

        return Employee::active()->withJobFunction('technician')
            ->where('ai_scheduling_enabled', true)
            ->whereIn('entity_id', $poolIds)
            ->exists();
    }

    private static function scheduleWithAiFields(Job $record): array
    {
        $parsed = (new RequestedSlotParser)->parse($record);

        return [
            DatePicker::make('ai_date')
                ->label('Target date (Pacific)')
                ->native(false)
                ->default($parsed->date)
                ->required()
                ->live()
                ->helperText('Prefilled from what the customer asked for — check it before starting.'),
            Radio::make('ai_firmness')
                ->label('How strict is the time?')
                ->options([
                    SchedulingConstraint::FIRM_EXACT => 'Exact time',
                    SchedulingConstraint::FIRM_WINDOW => 'Part of day',
                    SchedulingConstraint::FIRM_AFTER => 'After a time',
                    SchedulingConstraint::FIRM_FLEXIBLE => 'Any time that day',
                ])
                ->default($parsed->firmness)
                ->required()
                ->live(),
            TimePicker::make('ai_time')
                ->label('Exact time')
                ->seconds(false)
                ->default($parsed->exactTime)
                ->visible(fn ($get) => $get('ai_firmness') === SchedulingConstraint::FIRM_EXACT)
                ->required(fn ($get) => $get('ai_firmness') === SchedulingConstraint::FIRM_EXACT),
            Select::make('ai_part_of_day')
                ->label('Part of day')
                ->options(['morning' => 'Morning', 'afternoon' => 'Afternoon', 'evening' => 'Evening'])
                ->default($parsed->partOfDay === 'any' ? 'morning' : $parsed->partOfDay)
                ->visible(fn ($get) => $get('ai_firmness') === SchedulingConstraint::FIRM_WINDOW)
                ->required(fn ($get) => $get('ai_firmness') === SchedulingConstraint::FIRM_WINDOW),
            Select::make('ai_after_time')
                ->label('After what time?')
                ->options(TimePreference::afterTimeOptions())
                ->native(false)
                // Prefilled from the job's own "After 10am" request; the
                // earliest bound IS that hour for an after-constraint.
                ->default($parsed->firmness === SchedulingConstraint::FIRM_AFTER ? $parsed->earliest : null)
                ->visible(fn ($get) => $get('ai_firmness') === SchedulingConstraint::FIRM_AFTER)
                ->required(fn ($get) => $get('ai_firmness') === SchedulingConstraint::FIRM_AFTER)
                ->helperText('Anything from this hour to close is offered to the techs.'),
            // Not required here — unlike manual scheduling, a dispatcher
            // starting an AI session often doesn't know the warehouse yet.
            // Same as parts cost, it can be filled in later: the "Order
            // part" action already re-asks whenever the job still has none
            // (JobActions::needsWarehouse), so nothing is lost by deferring.
            ...JobForm::warehouseFields(),
            self::partGlassSection($record),
            Repeater::make('tech_order')
                ->label('Will contact, in order')
                ->helperText('Drag to reorder. Remove anyone you do not want contacted for this job - only these techs are texted, in this order.')
                ->simple(
                    Select::make('employee_id')
                        ->options(self::aiTechOptions($record))
                        ->distinct()
                        ->selectablePlaceholder(false)
                        ->required(),
                )
                ->default(array_keys(self::aiTechOptions($record)))
                ->addActionLabel('Add a technician')
                ->minItems(1)
                ->reorderableWithDragAndDrop()
                ->validationMessages(['min' => 'Keep at least one technician on the list.']),
            Select::make('tech_mode')
                ->label('Technician mode')
                ->options([
                    AiSchedulingSession::MODE_SIMULATE => 'Simulate — sends nothing, for testing the negotiation',
                    AiSchedulingSession::MODE_APPROVE => 'Approve each text before it sends',
                    AiSchedulingSession::MODE_LIVE => 'Live — fully autonomous',
                ])
                ->default(Setting::get('ai_scheduling_default_mode', 'simulate'))
                ->required(),
            Select::make('customer_mode')
                ->label('Customer mode')
                ->options([
                    AiSchedulingSession::MODE_OFF => 'Off — never text the customer',
                    AiSchedulingSession::MODE_SIMULATE => 'Simulate',
                    AiSchedulingSession::MODE_APPROVE => 'Approve each text before it sends',
                    AiSchedulingSession::MODE_LIVE => 'Live',
                ])
                ->default(AiSchedulingSession::MODE_OFF)
                ->required()
                ->helperText('Only matters if the customer texts back asking for a different day/time. Off also means a customer text never pauses the session - you are handling that thread yourself.'),
        ];
    }

    /**
     * The opted-in techs at this job's location, in roster-ranking order
     * (Employee::scopeOrderByPriority) — which is the DEFAULT contact order
     * the modal's repeater starts from before anyone drags a row.
     *
     * No "#1 ·" prefix on the labels here, unlike the tech pickers elsewhere:
     * the list itself is now the order, so a rank stuck to a name that has
     * been dragged into third place would read as a contradiction.
     *
     * @return array<int, string> employee id => label
     */
    private static function aiTechOptions(Job $record): array
    {
        $poolIds = $record->entity?->techPoolEntityIds() ?? array_filter([$record->entity_id]);

        $techs = Employee::active()->withJobFunction('technician')
            ->where('ai_scheduling_enabled', true)
            ->whereIn('entity_id', $poolIds)
            ->with('entity')
            ->orderByPriority()
            ->get();

        // LA + SFV share a pool, so say which location a tech belongs to;
        // Del Mar ranks alone and the code would just be noise.
        $showEntity = count($poolIds) > 1;

        return $techs->mapWithKeys(fn (Employee $t) => [
            $t->id => $showEntity && filled($t->entity?->code)
                ? $t->name.' · '.$t->entity->code
                : $t->name,
        ])->all();
    }

    /**
     * The repeater's flat list of employee ids (simple repeaters dehydrate to
     * the inner field's values), cleaned up. Null when nothing came back, so
     * the session falls back to the roster ranking rather than storing an
     * empty allow-list that would contact nobody.
     *
     * @return array<int, int>|null
     */
    private static function techOrderFromAiForm(array $data): ?array
    {
        $ids = array_values(array_unique(array_map(
            'intval',
            array_filter($data['tech_order'] ?? [], fn ($id) => filled($id)),
        )));

        return $ids === [] ? null : $ids;
    }

    private static function constraintFromAiForm(array $data): SchedulingConstraint
    {
        $date = Carbon::parse($data['ai_date'], Timezone::display())->format('Y-m-d');

        return match ($data['ai_firmness'] ?? SchedulingConstraint::FIRM_FLEXIBLE) {
            SchedulingConstraint::FIRM_EXACT => SchedulingConstraint::exact(
                $date, Carbon::parse($data['ai_time'])->format('H:i'),
            ),
            SchedulingConstraint::FIRM_WINDOW => SchedulingConstraint::window(
                $date, $data['ai_part_of_day'] ?? 'morning',
            ),
            SchedulingConstraint::FIRM_AFTER => SchedulingConstraint::after(
                $date, Carbon::parse($data['ai_after_time'])->format('H:i'),
            ),
            default => SchedulingConstraint::flexible($date),
        };
    }

    /** Prefill the schedule/reschedule form from the job's current state. */
    private static function scheduleFormDefaults(Job $record): array
    {
        $local = Timezone::toDisplay($record->scheduled_at);

        return [
            'scheduled_date' => $local?->format('Y-m-d'),
            'scheduled_time' => $local?->format('H:i'),
            'time_phrasing' => $record->scheduled_exact_time
                ? 'exact'
                : ($record->scheduled_window_hours ? 'custom_window' : 'window'),
            'window_hours' => $record->scheduled_window_hours,
            'technician_id' => $record->technician_id,
            'warehouse_id' => $record->warehouse_id,
            'warehouse_other' => $record->warehouse_other,
            // Only read by partGlassSection(), which renders on a mismatch only.
            'part_glass_position' => $record->glass_position,
            'part_glass_number' => $record->partLabels()[0] ?? null,
        ];
    }

    /** Apply the schedule/reschedule form data to the job via the lifecycle. */
    private static function applySchedule(Job $record, array $data): void
    {
        // Combine the separate date + time fields into one Pacific wall-clock
        // instant; lifecycle()->schedule converts it to UTC.
        $date = Carbon::parse($data['scheduled_date'], Timezone::display())->format('Y-m-d');
        $time = Carbon::parse($data['scheduled_time'])->format('H:i');
        $scheduledLocal = Carbon::parse("{$date} {$time}", Timezone::display());

        // "Other" sentinel → no warehouse_id; the location lives in warehouse_other.
        $warehouseId = ($data['warehouse_id'] ?? null) === JobForm::WAREHOUSE_OTHER ? null : ($data['warehouse_id'] ?? null);

        self::lifecycle()->schedule(
            $record,
            $scheduledLocal,
            self::actor(),
            technicianId: $data['technician_id'] ?? null,
            warehouseId: $warehouseId,
            warehouseOther: $data['warehouse_other'] ?? null,
            exactTime: ($data['time_phrasing'] ?? 'window') === 'exact',
            windowHours: ($data['time_phrasing'] ?? 'window') === 'custom_window'
                ? (int) $data['window_hours'] : null,
        );
    }

    /**
     * "Tech cancelled" — the assigned tech backed out but the appointment stands.
     * Removes ONLY the tech (keeps the same date/time), texts NObody, and drops the
     * job onto the "Needs tech assigned" queue so a new tech can be assigned to the
     * exact same slot. To move the time use Reschedule; to pull the appointment off
     * the calendar entirely use Cancel appointment.
     */
    public static function techCancelled(): Action
    {
        return Action::make('techCancelled')
            ->label('Tech cancelled')
            ->icon('heroicon-o-user-minus')
            ->color('warning')
            ->modalHeading('Tech cancelled on this job?')
            ->modalDescription('The assigned tech backed out. This removes the tech but keeps the appointment (same date and time) — the customer is NOT texted. The job moves to "Needs tech assigned" so you can put a new tech on the same slot. To change the time use Reschedule; to cancel the appointment entirely use Cancel appointment.')
            ->modalSubmitActionLabel('Remove tech')
            ->visible(fn (Job $record) => $record->status === JobStatus::Scheduled && $record->technician_id !== null)
            ->schema([
                TextInput::make('reason')->label('Reason (optional)')
                    ->placeholder('Tech double-booked, called out sick, etc.'),
            ])
            ->action(function (Job $record, array $data) {
                self::lifecycle()->techCancelled($record, $data['reason'] ?? null, self::actor());
                Notification::make()
                    ->title('Tech removed — job needs a new tech assigned')
                    ->body('The appointment time is unchanged and the customer was not texted.')
                    ->success()->send();
            });
    }

    public static function assignTech(): Action
    {
        return Action::make('assignTech')
            ->label('Assign tech')
            ->icon('heroicon-o-wrench-screwdriver')
            ->visible(fn (Job $record) => ! $record->status->isClosed())
            ->fillForm(fn (Job $record) => ['technician_id' => $record->technician_id])
            ->schema([
                Select::make('technician_id')->label('Technician')->options(self::technicianOptions())->searchable()->required(),
            ])
            ->action(function (Job $record, array $data) {
                self::lifecycle()->assignTechnician($record, $data['technician_id'], self::actor());
                Notification::make()->title('Technician assigned')->success()->send();
            });
    }

    /**
     * "Text techs for availability": sends the Spanish availability request
     * ("can you take this job? what time?") from the Quo number to the techs
     * you pick at this job's location — the same request the send-welcome
     * screen offers right after intake, available here on any open job.
     * Idempotent per job+tech (via the outbox), so re-sending never
     * double-texts a tech.
     */
    public static function textTechsAvailability(): Action
    {
        return Action::make('textTechsAvailability')
            ->label('Text techs for availability')
            ->icon('heroicon-o-device-phone-mobile')
            ->color('info')
            ->visible(fn (Job $record) => ! $record->status->isClosed()
                && self::locationTechOptions($record) !== [])
            ->modalHeading('Text techs — tentative availability')
            ->modalDescription('Sends the Spanish availability request from the Quo number to the techs you pick. You\'ll still call to confirm — this just starts the conversation.')
            ->modalSubmitActionLabel('Send texts')
            ->schema([
                CheckboxList::make('tech_ids')
                    ->label(fn (Job $record) => 'Technicians — '.($record->entity?->techPoolLabel() ?? 'this location'))
                    ->options(fn (Job $record) => self::locationTechOptions($record))
                    ->required()
                    ->helperText(fn (Job $record) => ($preview = self::preview($record, TemplateKey::TechJobAvailability)) !== null
                        ? "Each tech receives:\n\n".$preview
                        : '⚠ Template missing/inactive — check Message Templates.'),
            ])
            ->action(function (Job $record, array $data) {
                $count = self::lifecycle()->sendTechAvailabilityRequests($record, $data['tech_ids'] ?? [], self::actor());

                Notification::make()
                    ->title($count > 0 ? "Texted {$count} tech(s)" : 'No techs texted')
                    ->body($count > 0
                        ? 'Availability requests queued from the Quo number.'
                        : 'Check the selected techs have a valid phone.')
                    ->status($count > 0 ? 'success' : 'warning')
                    ->send();
            });
    }

    /**
     * Active technicians attached to this job's entity (location) — LA and SFV are
     * pooled. Listed by priority ranking (first choice first, unranked last), with
     * the rank shown in the label so the order reads as deliberate.
     */
    private static function locationTechOptions(Job $record): array
    {
        return Employee::active()->withJobFunction('technician')
            ->whereIn('entity_id', $record->entity?->techPoolEntityIds() ?? array_filter([$record->entity_id]))
            ->orderByPriority()
            ->get()
            ->mapWithKeys(fn (Employee $tech) => [
                $tech->id => $tech->priorityLabel() === null
                    ? $tech->name
                    : $tech->priorityLabel().' · '.$tech->name,
            ])
            ->all();
    }

    /**
     * On an unpaid job, completing the work forces confirming how the customer
     * will pay — and texts them the matching "Job Complete - …" message (Zelle
     * reuses the original job_complete body; Credit Card creates/texts the
     * Stripe link inside its own Job Complete - Credit Card body; Venmo/Cash/
     * Check text their own Job Complete - … clones). A job that's already paid
     * skips the question entirely and gets the plain job_complete thank-you.
     */
    /**
     * Multi-day install, day one. Takes the place of "Mark work complete" until
     * the FINAL visit: the job isn't finished, so instead of the payment/review
     * flow we ask for the second visit's date. Given one, the job is re-scheduled
     * on the spot and the customer gets the "next part of your service" text;
     * without one it drops back onto the needs-scheduling queue and gets that
     * text whenever the second visit is booked (JobLifecycle::maybeSendTimeConfirmation).
     */
    public static function completeFirstInstallDay(): Action
    {
        return Action::make('completeFirstInstallDay')
            ->label('Mark day 1 complete')
            ->icon('heroicon-o-check-circle')
            ->color('primary')
            ->modalHeading('First install day complete')
            ->modalDescription('This is a multi-day install, so the job is not finished yet - no payment or completion text goes out. Schedule the second visit now if you have the date.')
            ->modalSubmitActionLabel('Save')
            ->visible(fn (Job $record) => $record->status === JobStatus::Scheduled
                && $record->needsSecondVisitScheduled())
            ->fillForm(fn (Job $record) => [
                'next_visit_known' => 'no',
                'technician_id' => $record->technician_id,
                'time_phrasing' => 'window',
            ])
            ->schema([
                Radio::make('next_visit_known')
                    ->label('Do you have the date for the second visit?')
                    ->options([
                        'yes' => 'Yes - schedule it now (texts the customer the new date)',
                        'no' => 'Not yet - send the job back to needs scheduling',
                    ])
                    ->required()
                    ->live(),
                ...self::secondVisitFields(),
            ])
            ->action(function (Job $record, array $data, Action $action) {
                if ($record->technician_id === null) {
                    Notification::make()->title('Assign a technician first')
                        ->body('A job needs a technician before any part of it can be marked complete.')->warning()->send();
                    $action->halt();
                }

                self::lifecycle()->completeFirstInstallDay($record, self::actor());

                if (($data['next_visit_known'] ?? 'no') !== 'yes') {
                    Notification::make()->title('Day 1 complete')
                        ->body('The job is back on the needs-scheduling queue. Schedule the second visit when the customer has a date - that is when they get the text.')
                        ->success()->send();

                    return;
                }

                self::scheduleSecondVisit($record->refresh(), $data);

                Notification::make()->title('Day 1 complete - second visit scheduled')
                    ->body('The customer was texted that the next part of their service is scheduled.')
                    ->success()->send();
            });
    }

    /**
     * Date/time/tech for the second visit of a multi-day install — the schedule
     * form minus the warehouse and same-day-order sections (the part is already
     * ordered and installed). Only shown once the dispatcher says they have a date.
     */
    private static function secondVisitFields(): array
    {
        $hasDate = fn ($get) => $get('next_visit_known') === 'yes';

        return [
            DatePicker::make('scheduled_date')
                ->label('Second visit date (Pacific)')
                ->native(false)
                ->visible($hasDate)
                ->required($hasDate),
            TimePicker::make('scheduled_time')
                ->label('Second visit time (Pacific)')
                ->seconds(false)
                ->visible($hasDate)
                ->required($hasDate),
            Radio::make('time_phrasing')
                ->label('Customer text says')
                ->options([
                    'window' => '2-hour window',
                    'custom_window' => 'Different time window',
                    'exact' => 'Exact time',
                ])
                ->default('window')
                ->visible($hasDate)
                ->required($hasDate)
                ->live(),
            Select::make('window_hours')
                ->label('Window length')
                ->options([1 => '1 hour (e.g. 8am-9am)', 3 => '3 hours (e.g. 8am-11am)', 4 => '4 hours (e.g. 8am-12pm)'])
                ->visible(fn ($get) => $hasDate($get) && $get('time_phrasing') === 'custom_window')
                ->required(fn ($get) => $hasDate($get) && $get('time_phrasing') === 'custom_window'),
            Select::make('technician_id')->label('Technician')->options(self::technicianOptions())->searchable()
                ->visible($hasDate)
                ->required($hasDate)
                ->helperText('Defaults to the tech who did the first day.'),
        ];
    }

    /** Book the second visit from the day-1 completion form. */
    private static function scheduleSecondVisit(Job $record, array $data): void
    {
        $date = Carbon::parse($data['scheduled_date'], Timezone::display())->format('Y-m-d');
        $time = Carbon::parse($data['scheduled_time'])->format('H:i');

        self::lifecycle()->schedule(
            $record,
            Carbon::parse("{$date} {$time}", Timezone::display()),
            self::actor(),
            technicianId: $data['technician_id'] ?? null,
            exactTime: ($data['time_phrasing'] ?? 'window') === 'exact',
            windowHours: ($data['time_phrasing'] ?? 'window') === 'custom_window'
                ? (int) $data['window_hours'] : null,
        );
    }

    public static function markWorkComplete(): Action
    {
        return Action::make('markWorkComplete')
            ->label('Mark work complete')
            ->icon('heroicon-o-check-circle')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Mark work complete')
            ->modalDescription(fn (Job $record) => $record->isFullyPaid()
                ? 'The customer has already paid — this wraps the job up.'
                : 'Confirm with the customer how they want to pay — we\'ll text them the matching "Job Complete" message (credit card texts the secure Stripe link).')
            // A multi-day install's FIRST day goes through completeFirstInstallDay
            // instead; this is the real completion, so it waits for the last visit.
            ->visible(fn (Job $record) => $record->status === JobStatus::Scheduled
                && ! $record->needsSecondVisitScheduled())
            ->fillForm(fn (Job $record) => [
                'payment_preference' => $record->payment_preference?->value ?? PaymentMethod::Zelle->value,
            ])
            ->schema([
                Radio::make('payment_preference')
                    ->label('How is the customer paying?')
                    ->options([
                        PaymentMethod::Zelle->value => 'Zelle — texts the Job Complete - Zelle message',
                        PaymentMethod::Stripe->value => 'Credit card — creates a card payment link and texts it',
                        PaymentMethod::Venmo->value => 'Venmo — texts the Job Complete - Venmo message',
                        PaymentMethod::Cash->value => 'Cash — texts the Job Complete - Cash message',
                        PaymentMethod::Check->value => 'Check — texts the Job Complete - Check message',
                    ])
                    ->required()
                    ->visible(fn (Job $record) => ! $record->isFullyPaid()),
            ])
            ->action(function (Job $record, array $data, Action $action) {
                if ($record->technician_id === null) {
                    Notification::make()->title('Assign a technician first')
                        ->body('A job needs a technician before it can be marked complete.')->warning()->send();
                    $action->halt();
                }

                $preference = ! $record->isFullyPaid() && filled($data['payment_preference'] ?? null)
                    ? PaymentMethod::from($data['payment_preference'])
                    : null;

                $neededCalibration = $record->needsCalibration();

                // The generic job_complete thank-you only goes out when no
                // payment-preference text will also fire for this completion
                // (i.e. the job was already paid, so there's no preference).
                self::lifecycle()->markWorkComplete(
                    $record, self::actor(), sendCompleteText: $preference === null,
                );

                $followUp = $preference === null ? null : self::sendPaymentPreference($record, $preference);

                Notification::make()->title('Work marked complete')
                    ->body($followUp)->success()->send();

                // The review call-first queue just opened (skipped for a
                // calibration job — it waits until ops confirms calibration).
                if (! $neededCalibration) {
                    self::sendCallForReviewNotification($record->refresh());
                }
            });
    }

    /**
     * Nudge whoever just completed the job to call the customer for a review
     * right away, while the visit is fresh — with one-click buttons to open
     * the customer's Quo conversation and the Reviews dashboard.
     */
    private static function sendCallForReviewNotification(Job $record): void
    {
        $customer = $record->customer;
        $firstName = $customer?->first_name ?: 'the customer';
        $quoUrl = $customer?->quo_conversation_url;

        Notification::make()
            ->title("Call {$firstName} now for a review")
            ->body("The job just finished - reviews land best while it's still fresh.")
            ->success()
            ->actions(array_filter([
                filled($quoUrl) ? Action::make('call')->label('Call now')->color('primary')
                    ->url($quoUrl, shouldOpenInNewTab: true) : null,
                Action::make('reviews')->label('Reviews dashboard')
                    ->url(ReviewRequestResource::getUrl('index')),
            ]))
            ->send();
    }

    /**
     * Store the confirmed preference + fire the matching payment text. Returns
     * a human line for the "work complete" notification. Never blocks completion
     * — a text that can't go out (missing entity details, Stripe not set up,
     * opted-out customer) surfaces as its own warning instead.
     */
    private static function sendPaymentPreference(Job $record, PaymentMethod $preference): ?string
    {
        $message = self::lifecycle()->confirmPaymentPreference($record, $preference, self::actor());

        if ($preference === PaymentMethod::Stripe) {
            try {
                $sent = self::hasResendableLink($record)
                    ? self::lifecycle()->resendStripeInvoiceLink($record, self::actor(), TemplateKey::JobCompleteCreditCard)
                    : self::lifecycle()->createAndTextStripeInvoice($record, self::actor(), TemplateKey::JobCompleteCreditCard);

                return $sent !== null
                    ? 'Card payment link texted to the customer.'
                    : 'Card link created, but the text was blocked (customer may be opted out). Link: '.$record->refresh()->stripe_invoice_url;
            } catch (\Throwable $e) {
                Notification::make()->title('Card payment link not sent')
                    ->body($e->getMessage())->warning()->send();

                return null;
            }
        }

        // Zelle / Venmo / Cash / Check.
        if ($message === null) {
            Notification::make()->title($preference->label().' payment text not sent')
                ->body('The customer may be opted out / paused, or no sending number is configured.')
                ->warning()->send();

            return null;
        }

        return $preference->label().' payment details texted to the customer.';
    }

    public static function recordPayment(): Action
    {
        return Action::make('recordPayment')
            ->label('Record payment')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            // Visible until the quote is collected in full (so a partial deposit
            // leaves the action available to record the balance later).
            ->visible(fn (Job $record) => ! $record->isFullyPaid() && ! $record->status->isClosed() || $record->status === JobStatus::WorkCompleted)
            // Recording payment on a job whose work isn't marked complete yet is
            // valid (customers often pay up front) but easy to do by accident, so
            // warn and make them confirm.
            ->modalHeading(fn (Job $record) => $record->isWorkComplete() ? 'Record payment' : 'Record payment before completion?')
            ->modalDescription(fn (Job $record) => new HtmlString(self::recordPaymentModalBody($record)))
            ->schema([
                TextInput::make('amount')->label('Amount collected ($)')->numeric()->required()
                    ->helperText('Enter less than the balance due to record a partial / deposit payment — the rest stays owed.')
                    // Default to the outstanding balance (the full quote on a first
                    // payment), so the common "collect the rest" case is one click.
                    ->default(fn (Job $record) => $record->isPaid()
                        ? ($record->balanceDueCents() > 0 ? $record->balanceDueCents() / 100 : null)
                        : ($record->quote_amount_cents ? $record->quote_amount_cents / 100 : null)),
                Select::make('payment_method')->options(PaymentMethod::options())->required(),
                Textarea::make('payment_notes')->rows(2),
            ])
            ->action(function (Job $record, array $data, Action $action) {
                if ($record->technician_id === null) {
                    Notification::make()->title('Assign a technician first')
                        ->body('Cash is tracked against the tech who collected it, so a job needs a technician before payment can be recorded.')
                        ->warning()->send();
                    $action->halt();
                }
                self::lifecycle()->recordPayment(
                    $record,
                    Money::parseToCents((string) $data['amount']) ?? 0,
                    PaymentMethod::from($data['payment_method']),
                    null,
                    $data['payment_notes'] ?? null,
                    self::actor(),
                );

                $record->refresh();
                $balance = $record->balanceDueCents();
                Notification::make()
                    ->title($balance > 0 ? 'Partial payment recorded' : 'Payment recorded')
                    ->body($balance > 0 ? Money::format($balance).' balance still due.' : null)
                    ->success()->send();
            });
    }

    /**
     * The Record-payment modal blurb: the not-complete-yet warning (payment before
     * the work is done is easy to do by accident) plus, once a partial deposit is
     * on file, what's collected and what's still due.
     */
    private static function recordPaymentModalBody(Job $record): string
    {
        $lines = [];

        if (! $record->isWorkComplete()) {
            $lines[] = "⚠️ This job hasn't been marked complete yet. Are you sure you want to record payment before the work is done?";
        }

        if ($record->isPaid() && $record->balanceDueCents() > 0) {
            $lines[] = 'Already collected <strong>'.Money::format((int) $record->amount_collected_cents)
                .'</strong> of '.Money::format((int) $record->quote_amount_cents)
                .' — <strong>'.Money::format($record->balanceDueCents()).' balance due</strong>.';
        }

        return implode('<br><br>', $lines);
    }

    /**
     * Correction path for the additive ledger: remove a mis-entered payment line.
     * Reverses that line's cash off the tech balance and re-syncs the job's
     * collected total. Only shown when the job has payments and the user can
     * record payments; a job with a single payment is the common "undo the one I
     * just entered" case.
     */
    public static function removePayment(): Action
    {
        return Action::make('removePayment')
            ->label('Remove a payment')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Job $record) => $record->payments()->exists()
                && (auth()->user()?->can('record_payment') ?? false))
            ->modalHeading('Remove a payment')
            ->modalDescription('Deletes the selected payment line and updates the collected total. Cash lines are backed off the tech\'s balance.')
            ->schema([
                Radio::make('payment_id')
                    ->label('Which payment?')
                    ->options(fn (Job $record) => $record->payments->mapWithKeys(fn ($p) => [
                        $p->id => Timezone::toDisplay($p->collected_at)->format('M j, g:i A')
                            .' · '.($p->method?->label() ?? '—').' · '.Money::format((int) $p->amount_cents),
                    ])->all())
                    ->required(),
            ])
            ->action(function (Job $record, array $data) {
                $payment = $record->payments()->find($data['payment_id']);
                if ($payment === null) {
                    Notification::make()->title('That payment no longer exists')->warning()->send();

                    return;
                }

                self::lifecycle()->deletePayment($payment, self::actor());

                Notification::make()->title('Payment removed')
                    ->body('Collected total is now '.Money::format((int) $record->refresh()->amount_collected_cents).'.')
                    ->success()->send();
            });
    }

    /**
     * "I've eyeballed the deposit" — the manual ack that a recorded non-cash
     * payment actually landed, for when no Chase transaction has been matched to
     * it yet. Idempotent; purely a verification stamp (no amounts, no completion).
     * Cash is out of scope (the tech collects in person, tracked via balances),
     * so it never shows there.
     */
    public static function confirmPaymentReceived(): Action
    {
        return Action::make('confirmPaymentReceived')
            ->label('Confirm payment received')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Job $record) => $record->isPaid()
                && ! $record->isPaymentReceiptConfirmed()
                && (auth()->user()?->can('record_payment') ?? false))
            ->requiresConfirmation()
            ->modalHeading(fn (Job $record) => 'Confirm payment received - '.$record->customer_name_snapshot)
            ->modalDescription(fn (Job $record) => 'Mark the '.($record->payment_method?->label() ?? 'recorded')
                .' payment of '.Money::format($record->amount_collected_cents)
                .' as verified received. Do this once you\'ve seen the deposit land.')
            ->modalSubmitActionLabel('Yes, confirm received')
            ->action(function (Job $record) {
                self::lifecycle()->confirmPaymentReceived($record, self::actor());

                Notification::make()->title('Payment confirmed received')->success()->send();
            });
    }

    /**
     * Record the insurance carrier's payment on an insurance job. Separate from
     * the customer's deductible (recordPayment); this is the balance the insurer
     * covers and is tracked on the Insurance tab. Only shown for insurance jobs
     * that haven't been paid by the carrier yet.
     */
    public static function recordInsurancePayment(): Action
    {
        return Action::make('recordInsurancePayment')
            ->label('Record insurance payment')
            ->icon('heroicon-o-shield-check')
            ->color('success')
            ->visible(fn (Job $record) => $record->is_insurance
                && ! $record->isInsurancePaid()
                && (auth()->user()?->can('record_payment') ?? false))
            ->modalHeading(fn (Job $record) => 'Insurance payment - '.$record->customer_name_snapshot)
            // Pre-fill from the claim details already on the job, so recording the
            // check is usually a one-click confirm of the expected payout.
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
                TextInput::make('amount')->label('Insurance amount received ($)')->numeric()->prefix('$')->required()
                    ->helperText(fn (Job $record) => $record->insurance_expected_cents
                        ? 'Expected '.Money::format($record->insurance_expected_cents).' - change it if the carrier paid something else.'
                        : null),
                TextInput::make('reference')->label('Check / payment reference'),
                Textarea::make('insurance_notes')->label('Notes')->rows(2),
            ])
            ->action(function (Job $record, array $data) {
                self::lifecycle()->recordInsurancePayment(
                    $record,
                    Money::parseToCents((string) $data['amount']) ?? 0,
                    $data['reference'] ?? null,
                    $data['insurance_notes'] ?? null,
                    self::actor(),
                    $data['insurance_carrier'] ?? null,
                    $data['insurance_claim_number'] ?? null,
                );
                Notification::make()->title('Insurance payment recorded')->success()->send();
            });
    }

    /**
     * "Text card payment link": one click creates a Stripe Payment Link for the
     * quote plus a card surcharge (one line item) and texts the customer the
     * hosted pay-by-card URL. Uses Payment Links (not Invoices) to avoid Stripe's
     * Invoicing fee. Context-aware — once a link exists (and isn't paid yet) it
     * re-sends that same link instead of creating a second one. Card data never
     * touches this app. Gated by record_payment + Stripe being configured.
     */
    public static function stripeInvoice(): Action
    {
        return Action::make('stripeInvoice')
            ->label(fn (Job $record) => self::hasResendableLink($record)
                ? 'Re-send card payment link' : 'Text card payment link')
            ->icon('heroicon-o-credit-card')
            ->color('success')
            ->visible(fn (Job $record) => self::canStripeInvoice($record))
            ->requiresConfirmation()
            ->modalHeading(fn (Job $record) => self::hasResendableLink($record)
                ? 'Re-send the card payment link' : 'Create & text a card payment link')
            ->modalDescription(fn (Job $record) => new HtmlString(self::stripeModalBody($record)))
            ->modalSubmitActionLabel(fn (Job $record) => self::hasResendableLink($record)
                ? 'Re-send link' : 'Create & text link')
            ->action(function (Job $record, Action $action) {
                try {
                    $message = self::hasResendableLink($record)
                        ? self::lifecycle()->resendStripeInvoiceLink($record, self::actor())
                        : self::lifecycle()->createAndTextStripeInvoice($record, self::actor());
                } catch (\Throwable $e) {
                    Notification::make()->title('Could not send the payment link')
                        ->body($e->getMessage())->danger()->send();
                    $action->halt();
                }

                $url = $record->refresh()->stripe_invoice_url;

                if ($message === null) {
                    Notification::make()->title('Payment link ready — but the text didn\'t send')
                        ->body('The customer may be opted out or missing a mobile number. Link: '.$url)
                        ->warning()->send();

                    return;
                }

                self::sendDeliveryNotification(
                    'Card payment link sent',
                    'Payment link ready — texts are paused',
                    'Texted to '.($record->customer?->full_name ?: 'the customer').'.',
                    'Build mode is on; the link was logged but not delivered. Link: '.$url,
                );
            });
    }

    /** An unpaid job that already has a Stripe link — so the action re-sends it. */
    private static function hasResendableLink(Job $record): bool
    {
        return filled($record->stripe_invoice_url) && ! $record->isPaid();
    }

    /** Stripe action is available: permitted, configured, open, quoted, textable. */
    private static function canStripeInvoice(Job $record): bool
    {
        return (auth()->user()?->can('record_payment') ?? false)
            && app(StripeClient::class)->configured()
            && ! $record->status->isClosed()
            && ! $record->isPaid()
            && (int) ($record->quote_amount_cents ?? 0) > 0
            && ! (bool) $record->customer?->do_not_text;
    }

    /** Confirmation-modal HTML: the quote → surcharge → total breakdown. */
    private static function stripeModalBody(Job $record): string
    {
        $name = e($record->customer?->full_name ?: 'the customer');
        $phone = e($record->customer?->phone_e164 ?? '');

        if (self::hasResendableLink($record)) {
            return '<div style="font-size:.9rem;">Re-sends the existing Stripe payment link ('
                .Money::format($record->stripe_invoice_amount_cents).') to '.$name.' ('.$phone
                .'). No new link is created.</div>';
        }

        $pct = (float) config('glass.stripe.surcharge_percent', 3);
        $quote = (int) ($record->quote_amount_cents ?? 0);
        $total = (int) round($quote * (1 + $pct / 100));
        $pctLabel = rtrim(rtrim(number_format($pct, 2), '0'), '.');

        return '<div style="font-size:.9rem;line-height:1.6;">'
            .'Creates a Stripe payment link and texts it to '.$name.' ('.$phone.').'
            .'<div style="border:1px solid #22c55e55;background:#22c55e0d;border-radius:.5rem;padding:.6rem .8rem;margin-top:.5rem;">'
            .'Quote: '.Money::format($quote).'<br>'
            .'+ '.$pctLabel.'% card fee: '.Money::format($total - $quote).'<br>'
            .'<b>Total (one line item): '.Money::format($total).'</b>'
            .'</div></div>';
    }

    /**
     * "Send receipt": on-request receipt text for a paid job — receipts only go
     * out when the customer asks for one, never automatically. Texts a link to
     * a server-generated PDF (App\Services\Receipts\ReceiptPdfService), built
     * fresh from live job data on request — nothing is stored on disk. The link
     * is a 30-day Laravel temporary signed route; a re-send just mints a new
     * one. Shows a preview of the rendered text (with a real link) before
     * sending. Repeatable — a re-send makes a fresh manual outbox row.
     */
    public static function sendReceipt(): Action
    {
        return Action::make('sendReceipt')
            ->label('Send receipt')
            ->icon('heroicon-o-document-text')
            ->color('gray')
            // Only paid jobs have a receipt, and only textable customers get one.
            ->visible(fn (Job $record) => $record->isPaid()
                && ! (bool) $record->customer?->do_not_text
                && self::canRender($record, TemplateKey::Receipt, self::receiptExtraVars($record)))
            ->requiresConfirmation()
            ->modalHeading('Text a receipt to the customer')
            ->modalDescription(fn (Job $record) => self::previewModalBody(
                $record, TemplateKey::Receipt, 'This receipt texts to', self::receiptExtraVars($record),
            ))
            ->modalSubmitActionLabel('Send receipt')
            ->action(function (Job $record) {
                $message = self::lifecycle()->sendManualMessage(
                    $record, TemplateKey::Receipt, self::actor(), self::receiptExtraVars($record),
                );

                if ($message === null) {
                    Notification::make()
                        ->title('Receipt not sent')
                        ->body('The customer may be opted out, or the receipt could not be built.')
                        ->warning()->send();

                    return;
                }

                self::sendDeliveryNotification(
                    'Receipt is sending',
                    'Logged only — texts are paused',
                    'Receipt is on its way to '.($record->customer?->full_name ?: 'the customer').'.',
                    'Build mode is on; nothing was delivered. Un-pause in Admin → Integrations.',
                );
            });
    }

    /** {receipt_link}: a fresh 30-day signed URL to the PDF receipt for $record. */
    private static function receiptExtraVars(Job $record): array
    {
        return ['receipt_link' => app(ReceiptPdfService::class)->signedUrlFor($record)];
    }

    /**
     * The post-welcome "scheduling ask": the one text that turns a fresh lead
     * into a bookable appointment. Depending on what the customer gave us it
     * either ASKS for a time preference or ACKNOWLEDGES the one they gave, and
     * on a far-out date it offers to pull them earlier (App\Support\SchedulingAsk
     * picks which of the five templates that is).
     *
     * Deliberately dispatcher-pressed, never automatic — it opens a real
     * back-and-forth, so someone has to be ready to answer the reply. Hidden
     * once the job is scheduled (the question is moot) and for a job requested
     * today or in the past (dispatch calls those instead of texting).
     *
     * Pressed straight off the Send-welcome screen it would otherwise overtake
     * the delayed Quo dispatch intro, so it's held behind any still-pending
     * welcome (JobLifecycle::followUpSendAtUtc) rather than sent on click.
     */
    public static function sendSchedulingAsk(): Action
    {
        return Action::make('sendSchedulingAsk')
            ->label('Send scheduling text')
            ->icon('heroicon-o-calendar-days')
            ->color('info')
            ->visible(fn (Job $record) => self::schedulingAskFor($record) !== null)
            ->requiresConfirmation()
            ->modalHeading('Text the customer about scheduling')
            ->modalDescription(function (Job $record) {
                $ask = self::schedulingAskFor($record);

                if ($ask === null) {
                    return null;
                }

                return self::previewModalBody(
                    $record, $ask->template, 'This texts', $ask->vars,
                    timingPhrase: self::lifecycle()->followUpSendAtUtc($record) === null
                        ? 'now'
                        : 'right after the welcome text',
                );
            })
            ->modalSubmitActionLabel('Send text')
            ->action(function (Job $record) {
                $ask = SchedulingAsk::for($record);
                if ($ask === null) {
                    return;
                }

                // Held behind a welcome that hasn't left the outbox yet, so the
                // customer never reads the scheduling question before the intro.
                $sendAt = self::lifecycle()->followUpSendAtUtc($record);

                $message = self::lifecycle()
                    ->sendManualMessage($record, $ask->template, self::actor(), $ask->vars, $sendAt);

                if ($message === null) {
                    Notification::make()
                        ->title('Scheduling text not sent')
                        ->body('The customer may be opted out, or the text could not be built.')
                        ->warning()->send();

                    return;
                }

                $name = $record->customer?->full_name ?: 'the customer';

                self::sendDeliveryNotification(
                    $sendAt === null ? 'Scheduling text is sending' : 'Scheduling text is queued',
                    'Logged only — texts are paused',
                    $sendAt === null
                        ? 'On its way to '.$name.'.'
                        : 'Queued for '.$name.' — it goes out right after the welcome text.',
                    'Build mode is on; nothing was delivered. Un-pause in Admin → Integrations.',
                );
            });
    }

    /**
     * The scheduling ask for a job, or null when there isn't one to send:
     * already scheduled, customer opted out, or the copy can't render (which
     * also covers a template that's missing or switched off).
     */
    public static function schedulingAskFor(Job $record): ?SchedulingAsk
    {
        if ($record->status !== JobStatus::PendingSchedule
            || (bool) $record->customer?->do_not_text) {
            return null;
        }

        $ask = SchedulingAsk::for($record);

        return $ask !== null && self::canRender($record, $ask->template, $ask->vars) ? $ask : null;
    }

    /**
     * "Warranty / fix needed": a customer reported an issue on a job whose work
     * is done. Opens a warranty claim that lives on the Warranty dashboard
     * (Operations → Warranty), where the unpaid return visit gets scheduled.
     */
    public static function warrantyFixNeeded(): Action
    {
        return Action::make('warrantyFixNeeded')
            ->label('Warranty / fix needed')
            ->icon('heroicon-o-shield-exclamation')
            ->color('warning')
            // Only once the work is done (fully completed or awaiting payment),
            // and only one live claim per job.
            ->visible(fn (Job $record) => in_array($record->status, [JobStatus::WorkCompleted, JobStatus::Completed], true)
                && ! $record->warrantyClaims()->live()->exists())
            ->modalHeading('Open a warranty claim')
            ->modalDescription('Use when the customer reports an issue after the work was done. The job moves onto the Warranty dashboard, where you can add notes and schedule an unpaid return visit.')
            ->schema([
                Textarea::make('issue')
                    ->label('What did the customer report?')
                    ->rows(3)
                    ->required(),
            ])
            ->action(function (Job $record, array $data, Action $action) {
                try {
                    app(WarrantyService::class)
                        ->openForJob($record, $data['issue'], self::actor());
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Could not open the warranty claim')
                        ->body($e->getMessage())->warning()->send();
                    $action->halt();
                }

                Notification::make()->title('Warranty claim opened')
                    ->body('Schedule the return visit from Operations → Warranty.')
                    ->success()->send();
            });
    }

    /**
     * "Order part" / "Part links": shared by the Parts to Order page and the
     * post-schedule prompt. If the job has no warehouse yet, asks for one first
     * (persisted to the job) so the link preview can render live, then lists a
     * direct supplier search link (or a note) per part. "Mark ordered" stamps
     * the job via JobLifecycle::markPartsOrdered. Stays visible (relabeled) once
     * already ordered — re-opening it is harmless.
     */
    public static function orderParts(): Action
    {
        return Action::make('orderParts')
            ->label(fn (Job $record) => $record->isPartsOrdered() ? 'Part links' : 'Order part')
            ->icon('heroicon-o-truck')
            ->color('primary')
            // Nothing for us to order on a labor-only job (customer's own glass).
            ->visible(fn (Job $record) => $record->status === JobStatus::Scheduled
                && ! $record->isLaborOnly())
            ->modalHeading('Order the part(s)')
            ->fillForm(fn (Job $record) => [
                'warehouse_id' => $record->warehouse_id,
                'warehouse_other' => $record->warehouse_other,
                'parts_cost' => self::partsCostRows($record),
            ])
            ->schema(fn (Job $record) => [
                ...(self::needsWarehouse($record) ? JobForm::warehouseFields() : []),
                ...self::partsCostFields(),
                Placeholder::make('links')->label('')
                    ->content(fn (Job $record, $get) => self::orderLinksHtml($record, $get)),
                // "Other" (dealer / one-off) parts must be prepaid — no account
                // there. Offer to stamp the prepay here so the part isn't left on
                // hold. Shown when the job is (or is being set to) an "Other" job.
                Checkbox::make('parts_paid_confirm')
                    ->label("I've prepaid for this part")
                    ->helperText('This is an "Other" supplier — we prepay before they release the glass.')
                    ->default(fn (Job $record) => $record->isPartsPaid())
                    ->disabled(fn (Job $record) => $record->isPartsPaid())
                    ->visible(fn (Job $record, $get) => ($get('warehouse_id') === JobForm::WAREHOUSE_OTHER)
                        || $record->usesOtherWarehouse()),
            ])
            ->modalSubmitActionLabel('Mark ordered')
            ->action(function (Job $record, array $data) {
                if (self::needsWarehouse($record) && (filled($data['warehouse_id'] ?? null) || filled($data['warehouse_other'] ?? null))) {
                    $warehouseId = ($data['warehouse_id'] ?? null) === JobForm::WAREHOUSE_OTHER ? null : ($data['warehouse_id'] ?? null);
                    $record->warehouse_id = $warehouseId;
                    $record->warehouse_other = $data['warehouse_other'] ?? null;
                    $record->save();
                }

                self::applyPartsCost($record, $data['parts_cost'] ?? []);

                self::lifecycle()->markPartsOrdered($record, self::actor());

                if ($data['parts_paid_confirm'] ?? false) {
                    self::lifecycle()->markPartsPaid($record->refresh(), self::actor());
                }

                Notification::make()->title('Marked ordered')->success()->send();
            });
    }

    /**
     * The parts + cost repeater shown wherever we mark a part ordered. Intake
     * deliberately leaves the cost blank (we don't know it until we order), so
     * ordering is where it gets captured — and here it IS required, since by now
     * the supplier has quoted us. Every row must carry BOTH a label and a cost
     * (and the repeater can't be emptied out to zero rows), so an all-blank
     * submit can no longer stamp a job ordered with no part and no cost on file.
     *
     * $requiredUnlessSibling lets a caller make the whole thing conditionally
     * required on a boolean SIBLING field at the same schema level as this
     * repeater (the same-day order section only requires a cost once dispatch
     * ticks the "I've ordered the part" checkbox) — pass that field's name.
     * Omitted, it's unconditionally required (the Order-part modal / Parts to
     * Order quick-order action, where reaching this form at all means the
     * part is being ordered right now).
     *
     * Two different relative Get paths are needed for the SAME condition: the
     * repeater's own visible()/minItems() sit at the same level as the
     * sibling (a bare relative lookup reaches it), but each row's label/cost
     * sit two levels deeper (inside the repeater, inside that row), so
     * reaching the same sibling from there needs "../../".
     *
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function partsCostFields(?string $requiredUnlessSibling = null): array
    {
        $atRepeaterLevel = $requiredUnlessSibling === null
            ? true
            : fn ($get) => (bool) $get($requiredUnlessSibling);
        $atRowLevel = $requiredUnlessSibling === null
            ? true
            : fn ($get) => (bool) $get('../../'.$requiredUnlessSibling);

        return [
            Repeater::make('parts_cost')
                ->label('Part cost')
                ->helperText('What the part costs us - enter it now that we are ordering.')
                ->columnSpanFull()
                ->columns(3)
                ->addActionLabel('Add part')
                ->defaultItems(1)
                ->minItems($requiredUnlessSibling === null ? 1 : fn ($get) => $atRepeaterLevel($get) ? 1 : 0)
                ->visible($atRepeaterLevel)
                ->schema([
                    TextInput::make('label')->label('Part # / description')->columnSpan(2)
                        ->required($atRowLevel)
                        ->validationMessages(['required' => 'Enter the part number or description.']),
                    TextInput::make('cost')->label('Cost ($)')->numeric()->prefix('$')
                        ->required($atRowLevel)
                        ->validationMessages(['required' => 'Enter what this part costs.']),
                ]),
        ];
    }

    /**
     * Job parts -> parts_cost repeater rows (cost in dollars). Always yields at
     * least one row so there's something to type into.
     */
    public static function partsCostRows(Job $record): array
    {
        $rows = array_map(fn ($p) => [
            'label' => $p['label'] ?? null,
            'cost' => isset($p['cost_cents']) && $p['cost_cents'] !== null ? $p['cost_cents'] / 100 : null,
        ], JobForm::toPartsInput([
            'parts_json' => $record->parts_json ?? [],
            'part_number' => $record->part_number,
            'part_cost_cents' => $record->part_cost_cents,
        ]));

        return $rows === [] ? [['label' => $record->part_number, 'cost' => null]] : $rows;
    }

    /**
     * Persist the parts_cost rows back onto the job (parts_json + the summed
     * part_cost_cents + the primary part_number). The per-part "Tech paid" flag
     * isn't in this modal, so it's carried over from the existing row at the same
     * position rather than being silently dropped.
     */
    public static function applyPartsCost(Job $record, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $existing = array_values($record->parts_json ?? []);

        $input = [];
        foreach (array_values($rows) as $i => $row) {
            $input[] = [
                'label' => $row['label'] ?? null,
                'cost_cents' => Money::parseToCents((string) ($row['cost'] ?? '')),
                'tech_paid' => (bool) ($existing[$i]['tech_paid'] ?? false),
            ];
        }

        $mapped = JobForm::fromPartsInput(['parts_input' => $input]);

        $record->parts_json = $mapped['parts_json'];
        $record->part_number = $mapped['part_number'];
        $record->part_cost_cents = $mapped['part_cost_cents'];
        $record->tech_parts_cost_cents = $mapped['tech_parts_cost_cents'];
        $record->save();
    }

    /**
     * "Undo part ordered" — the stamp was a misclick, or the order was called
     * off. Puts the job back on Parts to Order. Shown only while the part is
     * stamped ordered; the prepay stamp (if any) comes off with it, so the
     * warning says so.
     */
    public static function undoPartsOrdered(): Action
    {
        return Action::make('undoPartsOrdered')
            ->label('Undo part ordered')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (Job $record) => ! $record->isLaborOnly() && $record->isPartsOrdered())
            ->modalHeading('Undo the part order?')
            ->modalDescription(fn (Job $record) => 'The job goes back onto Parts to Order.'
                .($record->isPartsPaid() ? ' The prepay stamp comes off too.' : ''))
            ->schema([
                TextInput::make('reason')
                    ->label('Why? (optional)')
                    ->placeholder('e.g. marked ordered by mistake'),
            ])
            ->modalSubmitActionLabel('Undo')
            ->action(function (Job $record, array $data) {
                self::lifecycle()->undoPartsOrdered($record, self::actor(), $data['reason'] ?? null);
                Notification::make()->title('Part order undone')
                    ->body('The job is back on Parts to Order.')
                    ->success()->send();
            });
    }

    /**
     * "Mark part paid" — stamps the prepay on an "Other"-supplier job whose part
     * we've ordered but not yet paid for. Only surfaces for those jobs (our own
     * warehouses are on account, so they never need it); clears the job from the
     * Prepay pending queue + stops the prepay Telegram alerts.
     */
    public static function markPartsPaid(): Action
    {
        return Action::make('markPartsPaid')
            ->label('Mark part paid')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Mark the part prepaid?')
            ->modalDescription('Confirms we\'ve prepaid the "Other" supplier for this part.')
            ->visible(fn (Job $record) => $record->status === JobStatus::Scheduled
                && $record->isPartsOrdered()
                && $record->needsPartsPrepaid())
            ->action(function (Job $record) {
                self::lifecycle()->markPartsPaid($record, self::actor());
                Notification::make()->title('Part marked paid')->success()->send();
            });
    }

    /**
     * "Mark part returned" — the tech brought back a part they'd picked up before
     * a cancellation. Clears the job off Parts → "Parts returned". Shown only
     * while a return is still outstanding.
     */
    public static function markPartReturned(): Action
    {
        return Action::make('markPartReturned')
            ->label('Mark part returned')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Mark the part returned?')
            ->modalDescription('Confirms the tech brought the picked-up part back.')
            ->visible(fn (Job $record) => $record->needsPartReturn())
            ->action(function (Job $record) {
                self::lifecycle()->markPartReturned($record, self::actor());
                Notification::make()->title('Part marked returned')->success()->send();
            });
    }

    private static function needsWarehouse(Job $record): bool
    {
        return $record->warehouse_id === null && blank($record->warehouse_other);
    }

    /** Live link preview honoring a warehouse just picked in the form (unsaved). */
    private static function orderLinksHtml(Job $record, $get): HtmlString
    {
        $warehouse = filled($get('warehouse_id')) ? Warehouse::find($get('warehouse_id')) : $record->warehouse;

        $links = app(PartsOrderLinks::class)->linksForJob($record, $warehouse);

        $rows = collect($links)->map(function (array $link) {
            $part = e($link['part'] ?? 'Part');
            if ($link['url'] !== null) {
                return '<div style="padding:.35rem 0;"><strong>'.$part.'</strong> — '
                    .'<a href="'.e($link['url']).'" target="_blank" rel="noopener" '
                    .'style="color:#2563eb;text-decoration:underline;">Order on link ↗</a></div>';
            }

            return '<div style="padding:.35rem 0;"><strong>'.$part.'</strong> — '
                .'<span style="color:#6b7280;font-style:italic;">'.e($link['note'] ?? '').'</span></div>';
        })->implode('');

        return new HtmlString('<div style="font-size:.95rem;">'.$rows.'</div>');
    }

    /**
     * "Put on hold" — the lead is real and still ours to book, but we are waiting
     * to hear back from the customer. Takes the job off the needs-scheduling
     * queue (board tab, Open Tasks, the ops digests) and onto the "On hold" one,
     * which the daily digest keeps posting so it can't be quietly forgotten.
     *
     * Only for a job still pending schedule — a booked or closed job has nothing
     * to wait for. The reason is required: a hold with no reason reads exactly
     * like a job somebody dropped.
     */
    public static function putOnSchedulingHold(): Action
    {
        return Action::make('putOnSchedulingHold')
            ->label('Put on hold')
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->visible(fn (Job $record) => $record->status === JobStatus::PendingSchedule
                && ! $record->isOnSchedulingHold()
                && (auth()->user()?->can('manage_jobs') ?? false))
            ->modalHeading('Put this job on hold?')
            ->modalDescription('Use when the job still needs scheduling but we are waiting to hear back from the customer. It comes off the "Needs scheduling" queue and onto "On hold" — the Telegram digest keeps listing it until the hold is released. No texts stop or send.')
            ->schema([
                TextInput::make('reason')
                    ->label('What are we waiting on?')
                    ->required()
                    ->maxLength(255)
                    ->datalist([
                        'Customer is checking their schedule',
                        'Waiting on the customer to approve the quote',
                        'Customer is checking with their insurance',
                        'Customer asked us to call back later',
                        'Waiting on the customer to confirm the vehicle/part',
                    ]),
            ])
            ->modalSubmitActionLabel('Put on hold')
            ->action(function (Job $record, array $data) {
                self::lifecycle()->placeOnSchedulingHold($record, $data['reason'], self::actor());
                Notification::make()->title('Job put on hold')
                    ->body('It is off the scheduling queue until you release the hold.')
                    ->success()->send();
            });
    }

    /** The customer came back — put the job back on the scheduling queue. */
    public static function releaseSchedulingHold(): Action
    {
        return Action::make('releaseSchedulingHold')
            ->label('Take off hold')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->visible(fn (Job $record) => $record->isOnSchedulingHold()
                && (auth()->user()?->can('manage_jobs') ?? false))
            ->modalHeading('Take this job off hold?')
            ->modalDescription(fn (Job $record) => 'On hold since '
                .(Timezone::toDisplay($record->scheduling_hold_at)?->format('D, M j') ?? '—')
                .' — "'.$record->scheduling_hold_reason.'". It goes back onto the "Needs scheduling" queue.')
            ->schema([
                TextInput::make('note')
                    ->label('What did the customer say? (optional)')
                    ->maxLength(255),
            ])
            ->modalSubmitActionLabel('Take off hold')
            ->action(function (Job $record, array $data) {
                self::lifecycle()->releaseSchedulingHold($record, self::actor(), $data['note'] ?? null);
                Notification::make()->title('Hold released')
                    ->body('The job is back on the scheduling queue.')
                    ->success()->send();
            });
    }

    /** Booked, then canceled — keep the job on record (reporting) but close it. */
    public static function markLost(): Action
    {
        return Action::make('markLost')
            ->label('Mark lost')
            ->icon('heroicon-o-hand-thumb-down')
            ->color('warning')
            ->modalHeading('Mark job lost')
            ->modalDescription('Use when a booked job was canceled. The job stays on record for reporting; automated messages stop.')
            ->visible(fn (Job $record) => ! $record->status->isClosed())
            ->schema([
                TextInput::make('reason')->label('Reason (canceled by customer, no-show, etc.)'),
            ])
            ->requiresConfirmation()
            ->action(function (Job $record, array $data) {
                self::lifecycle()->markLost($record, $data['reason'] ?? null, self::actor());
                Notification::make()->title('Job marked lost')->success()->send();
            });
    }

    /**
     * "Uncancel" a closed (canceled/lost) job — reopen it back to needs
     * scheduling, wiping the old appointment so it's a clean lead again.
     */
    public static function reopen(): Action
    {
        return Action::make('reopen')
            ->label('Uncancel / reopen')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('success')
            ->modalHeading('Reopen this job?')
            ->modalDescription('Moves it back to Needs scheduling and clears the old date, tech, and part order — it starts fresh like a new intake. Payments already recorded stay.')
            ->modalSubmitActionLabel('Reopen job')
            ->visible(fn (Job $record) => $record->status->isClosed()
                && (auth()->user()?->can('manage_jobs') ?? false))
            ->requiresConfirmation()
            ->action(function (Job $record) {
                self::lifecycle()->reopen($record, self::actor());
                Notification::make()->title('Job reopened — back to needs scheduling')->success()->send();
            });
    }

    /**
     * Permanent removal — only for jobs entered completely wrong. Admin-only and
     * irreversible; redirects to the jobs list afterward since the record is gone.
     */
    public static function deleteJob(): Action
    {
        return Action::make('deleteJob')
            ->label('Delete job')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn () => auth()->user()?->can('delete_jobs') ?? false)
            ->requiresConfirmation()
            ->modalHeading('Delete this job?')
            ->modalDescription('Removes the job from every view and queues it for a super admin to review under Issues — it\'s permanently purged once they clear it. Use only for a job entered completely wrong — to close out a real booking that fell through, use "Mark lost" instead.')
            ->modalSubmitActionLabel('Delete job')
            ->successRedirectUrl(JobResource::getUrl('index'))
            ->action(function (Job $record) {
                self::lifecycle()->deleteCompletely($record, self::actor());
                Notification::make()->title('Job deleted — queued for review')->success()->send();
            });
    }

    /**
     * "Call" button — opens the customer's Quo inbox conversation in a new tab
     * so the team can call/text from Quo. Only shown once we've captured the
     * conversation link (i.e. after the first Quo message is exchanged).
     */
    public static function call(): Action
    {
        return Action::make('call')
            ->label('Call')
            ->icon('heroicon-o-phone')
            ->color('gray')
            ->url(fn (Job $record) => $record->customer?->quo_conversation_url, shouldOpenInNewTab: true)
            ->visible(fn (?Job $record) => filled($record?->customer?->quo_conversation_url));
    }

    /**
     * "Send text" button — opens the customer's Quo inbox conversation in a new
     * tab so the team can text directly from Quo. Same gating as call(): only
     * shown once we've captured the conversation link, and hidden for an
     * opted-out customer.
     */
    public static function messaging(): Action
    {
        return Action::make('messaging')
            ->label('Send text')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->url(fn (Job $record) => $record->customer?->quo_conversation_url, shouldOpenInNewTab: true)
            ->visible(fn (?Job $record) => filled($record?->customer?->quo_conversation_url)
                && ! (bool) $record?->customer?->do_not_text);
    }

    private static function canRender(Job $job, TemplateKey $key, array $extraVars = []): bool
    {
        return self::preview($job, $key, $extraVars) !== null;
    }

    /** Rendered body for a template, or null if it can't render for this job. */
    private static function preview(Job $job, TemplateKey $key, array $extraVars = []): ?string
    {
        try {
            // Preview what the recipient will actually read: customer texts in
            // the customer's language, tech texts in the crew's default (Spanish).
            $language = $key->audience() === Audience::Tech
                ? Language::Spanish
                : $job->customer?->preferredLanguage();

            return app(TemplateRenderer::class)
                ->renderForJob($key, $job, self::actor()?->name, $extraVars, $language);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Outgoing texts globally paused (build mode)? */
    private static function textsPaused(): bool
    {
        return app()->environment('production')
            && Setting::get('outgoing_sms_paused') === '1';
    }

    /**
     * Shared "message is sending" / "texts are paused" result notification.
     * Title/body copy differs per action, so both variants are parameterized;
     * the paused-vs-not switching itself (and the status color) is the part
     * that was duplicated across stripeInvoice(), sendReceipt(), and sendTemplate().
     */
    private static function sendDeliveryNotification(string $sentTitle, string $pausedTitle, string $sentBody, string $pausedBody): void
    {
        Notification::make()
            ->title(self::textsPaused() ? $pausedTitle : $sentTitle)
            ->body(self::textsPaused() ? $pausedBody : $sentBody)
            ->status(self::textsPaused() ? 'warning' : 'success')
            ->send();
    }

    /**
     * Shared preview-modal body for a "send this text now" confirmation: a gray
     * "sends to X (phone) now" line (verb phrase parameterized — receipt vs.
     * generic template wording differ) followed by the blue-bordered rendered
     * preview box. Shared by sendReceipt() and sendTemplate().
     *
     * $timingPhrase replaces the trailing "now" for a send that's deliberately
     * held back (the scheduling ask waits on a pending welcome).
     */
    private static function previewModalBody(
        Job $record,
        TemplateKey $key,
        string $verbPhrase,
        array $extraVars = [],
        string $timingPhrase = 'now',
    ): HtmlString {
        return new HtmlString(
            '<div style="font-size:.85rem;color:#6b7280;margin-bottom:.4rem;">'.$verbPhrase.' '
            .e($record->customer?->full_name ?: 'the customer').' ('.e($record->customer?->phone_e164).') '
            .e($timingPhrase).'.</div>'
            .'<div style="border:1px solid #3b82f655;background:#3b82f60d;border-radius:.5rem;padding:.6rem .8rem;white-space:pre-wrap;font-size:.9rem;">'
            .self::previewBodyHtml(self::preview($record, $key, $extraVars)).'</div>'
        );
    }

    /**
     * The preview box's HTML for a rendered body. The text sends exactly as
     * rendered; this only tidies the DISPLAY — a long URL (the signed
     * {receipt_link} runs ~180 chars of query-string signature) is shown as a
     * compact clickable link (host + path, query hidden) instead of wrapping
     * across four lines of hex. Everything else is escaped verbatim.
     */
    private static function previewBodyHtml(string $body): string
    {
        $parts = preg_split('~(https?://\S+)~', $body, -1, PREG_SPLIT_DELIM_CAPTURE);

        return implode('', array_map(function (string $part) {
            if (! preg_match('~^https?://~', $part)) {
                return e($part);
            }

            $label = (parse_url($part, PHP_URL_HOST) ?: '').(parse_url($part, PHP_URL_PATH) ?: '');

            return '<a href="'.e($part).'" target="_blank" rel="noopener"'
                .' style="color:#2563eb;text-decoration:underline;word-break:break-all;">'
                .e($label !== '' ? $label : $part).'</a>';
        }, $parts ?: [$body]));
    }

    /**
     * Pause / resume AUTOMATED texts for this lead (the customer). Paused leads
     * receive no automated SMS (welcome, time confirmation, reminders, completion)
     * — manual dispatcher texts and phone calls are unaffected. Distinct from a
     * hard opt-out (do_not_text).
     */
    /**
     * Log a note on the job — appended as its own timestamped bubble (CRM style),
     * never edited in place, so the job reads chronologically.
     */
    public static function addNote(): Action
    {
        return Action::make('addNote')
            ->label('Add note')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->visible(fn () => auth()->user()?->can('manage_jobs') ?? false)
            ->modalHeading('Add a note')
            ->modalSubmitActionLabel('Save note')
            ->schema([
                Textarea::make('body')
                    ->hiddenLabel()
                    ->placeholder('What happened? e.g. "Called customer, no answer - left voicemail."')
                    ->rows(4)
                    ->required()
                    ->maxLength(2000),
            ])
            ->action(function (array $data, Job $record) {
                $record->noteEntries()->create([
                    'author_id' => self::actor()->id,
                    'body' => trim($data['body']),
                ]);

                Notification::make()->title('Note added')->success()->send();
            });
    }

    /**
     * Edit / delete a logged note — AUTHOR ONLY (see JobNote::isEditableBy; not even
     * a super_admin can rewrite someone else's note). Both are mounted from the
     * notes feed by id (`mountAction('editJobNote', { note: N })`), which resolves
     * the matching `…Action()` method on the ViewJob page.
     *
     * Neither hides history: an edit stamps `edited_at` (shown next to the original
     * time) and a delete is a soft delete that leaves a tombstone in the feed.
     */
    public static function editNote(): Action
    {
        return Action::make('editJobNote')
            ->label('Edit note')
            ->modalHeading('Edit note')
            ->modalDescription('The note will be marked as edited, showing both times.')
            ->modalSubmitActionLabel('Save note')
            ->fillForm(fn (array $arguments) => [
                'body' => self::ownNote($arguments['note'] ?? null)?->body,
            ])
            ->schema([
                Textarea::make('body')->hiddenLabel()->rows(4)->required()->maxLength(2000),
            ])
            ->action(function (array $arguments, array $data) {
                $note = self::ownNote($arguments['note'] ?? null);
                if ($note === null) {
                    return;
                }

                $note->update(['body' => trim($data['body']), 'edited_at' => now()]);

                Notification::make()->title('Note updated')->success()->send();
            });
    }

    public static function deleteNote(): Action
    {
        return Action::make('deleteJobNote')
            ->label('Delete note')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete this note?')
            ->modalDescription('The note text is removed, but the feed keeps a "Note deleted" entry at its original time.')
            ->modalSubmitActionLabel('Delete note')
            ->action(function (array $arguments) {
                $note = self::ownNote($arguments['note'] ?? null);
                if ($note === null) {
                    return;
                }

                $note->delete();

                Notification::make()->title('Note deleted')->success()->send();
            });
    }

    /**
     * The note behind an edit/delete click, but only if it belongs to the current
     * user — the id comes from the browser, so ownership is re-checked here and
     * not just when rendering the buttons.
     */
    private static function ownNote(mixed $id): ?JobNote
    {
        $note = $id ? JobNote::find($id) : null;

        return $note?->isEditableBy(self::actor()) ? $note : null;
    }

    public static function toggleAutoTextPause(): Action
    {
        return Action::make('toggleAutoTextPause')
            ->label(fn (?Job $record) => $record?->customer?->auto_text_paused
                ? 'Resume auto-texts' : 'Pause auto-texts')
            ->icon(fn (?Job $record) => $record?->customer?->auto_text_paused
                ? 'heroicon-o-play' : 'heroicon-o-pause')
            ->visible(fn (?Job $record) => (auth()->user()?->can('manage_jobs') ?? false)
                && $record?->customer !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (?Job $record) => $record?->customer?->auto_text_paused
                ? 'Resume automated texts?' : 'Pause automated texts?')
            ->modalDescription(fn (?Job $record) => $record?->customer?->auto_text_paused
                ? 'Automated texts (reminders, confirmations, etc.) will resume for this lead.'
                : 'This lead will receive NO automated texts. Manual texts and calls are unaffected.')
            ->action(function (Job $record) {
                $customer = $record->customer;
                if ($customer === null) {
                    return;
                }

                $paused = ! $customer->auto_text_paused;
                $customer->update(['auto_text_paused' => $paused]);

                Notification::make()
                    ->title($paused ? 'Auto-texts paused' : 'Auto-texts resumed')
                    ->body($paused
                        ? 'No automated texts will be sent to this lead until resumed.'
                        : 'Automated texts will be sent to this lead again.')
                    ->success()->send();
            });
    }

    /**
     * Flip this customer between English and Spanish. Every automated text
     * (welcome, confirmation, reminders, completion, reviews) then renders from
     * that language's template row, falling back to English where a template
     * has no translation yet. Also settable at intake ("Spanish speaker").
     */
    public static function toggleCustomerLanguage(): Action
    {
        return Action::make('toggleCustomerLanguage')
            ->label(fn (?Job $record) => $record?->customer?->isSpanishSpeaker()
                ? 'Texts in English' : 'Texts in Spanish')
            ->icon('heroicon-o-language')
            ->visible(fn (?Job $record) => (auth()->user()?->can('manage_jobs') ?? false)
                && $record?->customer !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (?Job $record) => $record?->customer?->isSpanishSpeaker()
                ? 'Text this customer in English?' : 'Text this customer in Spanish?')
            ->modalDescription(fn (?Job $record) => $record?->customer?->isSpanishSpeaker()
                ? 'Automated texts will switch back to English for this customer — including any already scheduled.'
                : 'Automated texts will go out in Spanish for this customer, on every job — including any already scheduled.')
            ->action(function (Job $record) {
                $customer = $record->customer;
                if ($customer === null) {
                    return;
                }

                $spanish = ! $customer->isSpanishSpeaker();

                // Rewrites texts already queued (reminders render when they're
                // scheduled, not when they send), so the switch applies now.
                $rewritten = app(JobLifecycle::class)->setCustomerLanguage(
                    $customer, $spanish ? Language::Spanish : Language::English,
                );

                Notification::make()
                    ->title($spanish ? 'Texting in Spanish' : 'Texting in English')
                    ->body($rewritten > 0
                        ? "Applies to every automated text for this customer. {$rewritten} already-scheduled text(s) rewritten."
                        : 'Applies to every automated text for this customer.')
                    ->success()->send();
            });
    }

    /** All quick actions, ordered for the row action menu / view header. */
    public static function all(): array
    {
        return [
            self::addNote(),
            self::sendSchedulingAsk(),
            self::schedule(),
            self::scheduleWithAi(),
            self::putOnSchedulingHold(),
            self::releaseSchedulingHold(),
            self::reschedule(),
            self::cancelAppointment(),
            self::techCancelled(),
            self::assignTech(),
            self::textTechsAvailability(),
            self::orderParts(),
            self::undoPartsOrdered(),
            self::markPartsPaid(),
            self::markPartReturned(),
            self::completeFirstInstallDay(),
            self::markWorkComplete(),
            self::recordPayment(),
            self::removePayment(),
            self::confirmPaymentReceived(),
            self::stripeInvoice(),
            self::sendReceipt(),
            self::recordInsurancePayment(),
            self::warrantyFixNeeded(),
            self::toggleAutoTextPause(),
            self::toggleCustomerLanguage(),
            self::markLost(),
            self::reopen(),
            self::deleteJob(),
        ];
    }
}
