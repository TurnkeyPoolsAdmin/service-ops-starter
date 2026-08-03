<?php

namespace App\Filament\Resources\Jobs\Schemas;

use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Filament\Pages\AiScheduling;
use App\Filament\Resources\Jobs\Support\JobActions;
use App\Filament\Resources\Jobs\Support\JobTimeline;
use App\Models\Job;
use App\Services\Messaging\TemplateRenderer;
use App\Support\Money;
use App\Support\PartNumberGlass;
use App\Support\Timezone;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class JobInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Summary')
                    ->key('summary')
                    ->headerActions([
                        self::editAction(
                            'editSummary',
                            fn (Job $record) => $record->only([
                                'entity_id', 'booking_salesperson_id', 'vehicle_year',
                                'vehicle_make', 'vehicle_model', 'vin', 'glass_position',
                                'needs_calibration', 'is_multi_day',
                            ]),
                            JobForm::summaryFields(),
                        ),
                    ])
                    ->columns(3)
                    ->schema([
                        TextEntry::make('job_number')->weight('bold'),
                        TextEntry::make('status')->badge()
                            ->formatStateUsing(fn (JobStatus $state) => $state->label())
                            ->color(fn (JobStatus $state) => $state->color()),
                        TextEntry::make('entity.name')->label('Entity'),
                        TextEntry::make('customer_name_snapshot')->label('Customer'),
                        TextEntry::make('customer_phone_snapshot')->label('Phone')->copyable(),
                        // Snapshot-first — a sales-dashboard lead's rep has no
                        // employees row here (Job::bookingSalespersonName()).
                        TextEntry::make('booking_salesperson')->label('Booking salesperson')
                            ->state(fn (Job $record) => $record->bookingSalespersonName())
                            ->placeholder('—'),
                        TextEntry::make('vehicle')->label('Vehicle')
                            ->state(fn ($record) => trim(implode(' ', array_filter([
                                $record->vehicle_year, $record->vehicle_make, $record->vehicle_model,
                            ]))) ?: $record->vehicle_raw),
                        TextEntry::make('glass_position')->placeholder('—'),
                        // The part number's 2nd letter names the glass, so this
                        // reads as a live cross-check of the two entries above
                        // it. Amber while nobody has answered for it (scheduling
                        // will stop for it), grey once it was overridden with a
                        // reason and sent to a manager.
                        TextEntry::make('part_glass_mismatch')->label('Part vs glass')
                            ->visible(fn ($record) => $record->hasPartGlassMismatch())
                            ->state(fn ($record) => $record->hasUnresolvedPartGlassMismatch()
                                ? 'Mismatch - check with sales'
                                : 'Mismatch - overridden')
                            ->badge()
                            ->color(fn ($record) => $record->hasUnresolvedPartGlassMismatch() ? 'warning' : 'gray')
                            ->tooltip(fn ($record) => PartNumberGlass::sentence($record->partGlassMismatch())),
                        TextEntry::make('needs_calibration')->label('Calibration')
                            ->visible(fn ($record) => $record->needsCalibration())
                            ->state(fn ($record) => $record->is_insurance && ! $record->needs_calibration
                                ? 'Needs calibration (insurance)'
                                : 'Needs calibration')
                            ->badge()
                            ->color('warning'),
                        // Which half of a two-visit install we're on, at a glance.
                        TextEntry::make('is_multi_day')->label('Multi-day install')
                            ->visible(fn ($record) => $record->is_multi_day)
                            ->state(fn ($record) => match (true) {
                                $record->needsSecondVisitScheduled() => 'Day 1 of 2',
                                $record->isAwaitingSecondVisit() => 'Day 2 - final visit',
                                default => 'Multi-day install',
                            })
                            ->badge()
                            ->color('info'),
                        // Parked waiting on the customer. The status still reads
                        // "Pending schedule" (a hold is not a status), so this
                        // badge is the only place the job says why nothing is
                        // moving — it carries the reason and how long it's sat.
                        TextEntry::make('scheduling_hold_at')->label('On hold')
                            ->visible(fn ($record) => $record->isOnSchedulingHold())
                            ->state(function ($record) {
                                $days = $record->schedulingHoldDays();

                                return $record->scheduling_hold_reason
                                    .' · '.match (true) {
                                        $days === 0 => 'since today',
                                        $days === 1 => '1 day',
                                        default => $days.' days',
                                    };
                            })
                            ->badge()
                            ->color('warning')
                            ->columnSpanFull(),
                        TextEntry::make('vin')->label('VIN')->placeholder('—'),
                        TextEntry::make('auto_text_paused')->label('Automated texts')
                            ->state(fn ($record) => $record->customer?->auto_text_paused ? 'Paused' : 'Active')
                            ->badge()
                            ->color(fn ($record) => $record->customer?->auto_text_paused ? 'warning' : 'success'),
                        TextEntry::make('preferred_language')->label('Texts in')
                            ->state(fn ($record) => $record->customer?->preferredLanguage()->label())
                            ->badge()
                            ->color(fn ($record) => $record->customer?->preferredLanguage()->color())
                            ->placeholder('—'),
                    ]),
                Section::make('Money')
                    ->key('money')
                    ->headerActions([
                        self::editAction(
                            'editMoney',
                            fn (Job $record) => [
                                'parts_input' => JobForm::toPartsInput($record->only([
                                    'parts_json', 'part_number', 'part_cost_cents',
                                ])),
                                'labor_only' => $record->labor_only,
                                'is_insurance' => $record->is_insurance,
                                'quote_amount_cents' => $record->quote_amount_cents,
                                'tech_pay_amount_cents' => $record->tech_pay_amount_cents,
                                'additional_labor_cents' => $record->additional_labor_cents,
                                'additional_labor_reason' => $record->additional_labor_reason,
                                'payment_preference' => $record->payment_preference?->value,
                            ],
                            JobForm::moneyFields(),
                            fn (array $data) => JobForm::fromPartsInput($data),
                        ),
                    ])
                    ->columns(3)
                    ->schema([
                        // Labor only: the customer's own glass, so the parts lines
                        // are replaced by one badge saying so.
                        TextEntry::make('labor_only')->label('Parts')
                            ->visible(fn ($record) => $record->isLaborOnly())
                            ->state('Labor only - customer has the part')
                            ->badge()
                            ->color('info')
                            ->columnSpanFull(),
                        TextEntry::make('parts')->label('Parts')
                            ->visible(fn ($record) => ! $record->isLaborOnly())
                            ->state(fn ($record) => self::partsSummary($record))
                            ->placeholder('—')->columnSpanFull(),
                        // Whether the glass is actually on order. The stamp is
                        // per JOB (JobLifecycle::markPartsOrdered), so it reads as
                        // one badge under the part lines, not one per part.
                        TextEntry::make('parts_ordered_at')->label('Part status')
                            ->visible(fn ($record) => ! $record->isLaborOnly())
                            ->badge()
                            ->color(fn ($record) => $record->isPartsOrdered() ? 'success' : 'warning')
                            ->state(fn ($record) => $record->isPartsOrdered() ? 'Ordered' : 'Not ordered')
                            ->helperText(fn ($record) => $record->isPartsOrdered()
                                ? 'Ordered '.Timezone::toDisplay($record->parts_ordered_at)?->format('D, M j, Y g:i A')
                                : null),
                        TextEntry::make('quote_amount_cents')
                            ->label(fn ($record) => $record->is_insurance ? 'Quote (deductible)' : 'Quote')
                            ->formatStateUsing(fn ($state) => Money::format($state)),
                        TextEntry::make('part_cost_cents')->label('Part cost (total)')
                            ->visible(fn ($record) => ! $record->isLaborOnly())
                            ->formatStateUsing(fn ($state) => Money::format($state)),
                        // At-a-glance only — the carrier's full picture (claim #,
                        // expected vs actual, outstanding) is its own section below.
                        TextEntry::make('is_insurance')->label('Insurance')
                            ->visible(fn ($record) => $record->is_insurance)
                            ->state(fn ($record) => $record->isInsurancePaid()
                                ? 'Carrier paid '.Money::format($record->insurance_amount_cents)
                                : 'Awaiting carrier'.($record->insuranceOutstandingCents() > 0
                                    ? ' — '.Money::format($record->insuranceOutstandingCents()).' expected'
                                    : ''))
                            ->badge()
                            ->color(fn ($record) => $record->isInsurancePaid() ? 'success' : 'warning')
                            ->helperText(fn ($record) => filled($record->insurance_carrier)
                                ? $record->insurance_carrier : null),
                        TextEntry::make('amount_collected_cents')->label('Collected')->formatStateUsing(fn ($state) => Money::format($state)),
                        TextEntry::make('balance_due')->label('Balance due')
                            ->state(fn ($record) => Money::format($record->balanceDueCents()))
                            ->badge()
                            ->color('warning')
                            ->visible(fn ($record) => $record->isPartiallyPaid()),
                        TextEntry::make('payment_method')
                            ->label(fn ($record) => $record->payments()->count() > 1 ? 'Latest payment method' : 'Payment method')
                            ->placeholder('—'),
                        TextEntry::make('payment_preference')
                            ->label('Expected payment')
                            ->formatStateUsing(fn ($state) => $state instanceof PaymentMethod ? $state->label() : $state)
                            ->helperText('How the customer plans to pay — sets the Job Complete text.')
                            ->visible(fn ($record) => ! $record->isFullyPaid())
                            ->placeholder('Not confirmed yet'),
                        TextEntry::make('payments_breakdown')->label('Payments')
                            ->state(fn ($record) => self::paymentsSummary($record))
                            ->visible(fn ($record) => $record->payments()->count() > 1)
                            ->columnSpanFull()
                            ->placeholder('—'),
                        TextEntry::make('stripe_invoice_url')->label('Card payment link')
                            ->visible(fn ($record) => filled($record->stripe_invoice_url))
                            ->state(fn ($record) => $record->isPaid()
                                ? 'Paid ('.Money::format($record->stripe_invoice_amount_cents).')'
                                : 'Open link — '.Money::format($record->stripe_invoice_amount_cents))
                            ->badge()
                            ->color(fn ($record) => $record->isPaid() ? 'success' : 'info')
                            ->url(fn ($record) => $record->stripe_invoice_url, shouldOpenInNewTab: true)
                            ->helperText(fn ($record) => $record->stripe_invoice_created_at
                                ? 'Link sent '.Timezone::toDisplay($record->stripe_invoice_created_at)->format('M j, g:i A') : null)
                            ->columnSpanFull(),
                        // Tech pay is fixed at the assigned tech's base cost;
                        // anything extra sits on its own line with a reason, and
                        // the two add up to what End-of-Day pays out.
                        TextEntry::make('tech_pay_amount_cents')
                            ->label(fn ($record) => $record->tech_paid_at !== null ? 'Tech pay' : 'Tech pay (base)')
                            ->formatStateUsing(fn ($state) => Money::format($state)),
                        TextEntry::make('additional_labor_cents')->label('Additional labor')
                            ->formatStateUsing(fn ($state) => Money::format($state))
                            ->helperText(fn ($record) => $record->additional_labor_reason
                                ? (JobForm::ADDITIONAL_PAY_REASONS[$record->additional_labor_reason] ?? $record->additional_labor_reason)
                                : null),
                        TextEntry::make('total_tech_pay')->label('Total pay')
                            ->state(fn ($record) => Money::format($record->expected_tech_pay_cents))
                            ->weight('bold')
                            ->helperText('Base + additional labor - what End-of-Day pays this tech.'),
                        TextEntry::make('tech_parts_cost_cents')->label('Parts paid by tech')
                            ->visible(fn ($record) => (int) ($record->tech_parts_cost_cents ?? 0) > 0)
                            ->formatStateUsing(fn ($state) => Money::format($state))
                            ->helperText('Reimbursed to the tech on top of labor.')
                            ->color('info'),
                        TextEntry::make('gross_margin_cents')->label('Gross margin')
                            ->state(fn ($record) => Money::format($record->gross_margin_cents))
                            ->weight('bold'),
                    ]),
                // The carrier's side of an insurance job, kept apart from Money
                // (which is the CUSTOMER's deductible + what they paid). Only
                // rendered on an insurance job; toggle "Insurance job" in Money
                // to make it appear.
                Section::make('Insurance')
                    ->key('insurance')
                    ->description('The carrier\'s side of this job. The customer\'s deductible lives in Money above.')
                    ->visible(fn (Job $record) => $record->is_insurance)
                    ->headerActions([
                        self::editAction(
                            'editInsurance',
                            fn (Job $record) => $record->only([
                                'insurance_carrier', 'insurance_claim_number', 'insurance_expected_cents',
                            ]),
                            JobForm::insuranceFields(),
                        )->modalHeading('Edit insurance details'),
                        JobActions::recordInsurancePayment(),
                    ])
                    ->columns(3)
                    ->schema([
                        TextEntry::make('insurance_carrier')->label('Carrier')
                            ->placeholder('Not recorded yet'),
                        TextEntry::make('insurance_claim_number')->label('Claim number')
                            ->copyable()->placeholder('Not recorded yet'),
                        TextEntry::make('insurance_status')->label('Carrier payment')
                            ->state(fn (Job $record) => $record->isInsurancePaid() ? 'Received' : 'Awaiting carrier')
                            ->badge()
                            ->color(fn (Job $record) => $record->isInsurancePaid() ? 'success' : 'warning'),
                        TextEntry::make('insurance_expected_cents')->label('Expected payout')
                            ->formatStateUsing(fn ($state) => Money::format($state))
                            ->placeholder('Not recorded yet'),
                        TextEntry::make('insurance_amount_cents')->label('Actual payout')
                            ->formatStateUsing(fn ($state) => Money::format($state))
                            ->helperText(fn (Job $record) => $record->isInsurancePaid()
                                ? 'Received '.Timezone::toDisplay($record->insurance_paid_at)->format('M j, Y')
                                : null)
                            ->placeholder('Not received yet'),
                        // The receivable: what we're still chasing the carrier for.
                        TextEntry::make('insurance_outstanding')->label('Outstanding')
                            ->state(fn (Job $record) => Money::format($record->insuranceOutstandingCents()))
                            ->badge()
                            ->color(fn (Job $record) => $record->insuranceOutstandingCents() > 0 ? 'warning' : 'success')
                            ->helperText(fn (Job $record) => ! $record->isInsurancePaid()
                                && (int) ($record->insurance_expected_cents ?? 0) === 0
                                ? 'Add an expected payout to track this claim as a receivable.'
                                : null),
                        TextEntry::make('insurance_reference')->label('Check / payment ref')
                            ->visible(fn (Job $record) => filled($record->insurance_reference))
                            ->placeholder('—'),
                        TextEntry::make('insurance_notes')->label('Notes')
                            ->visible(fn (Job $record) => filled($record->insurance_notes))
                            ->columnSpanFull()
                            ->placeholder('—'),
                    ]),
                Section::make('Scheduling')
                    ->key('scheduling')
                    ->headerActions([
                        self::editAction(
                            'editScheduling',
                            fn (Job $record) => $record->only([
                                'technician_id', 'service_address',
                                'requested_day', 'requested_time',
                                'requested_time_preference', 'requested_after_time',
                                'warehouse_id', 'warehouse_other',
                            ]),
                            JobForm::schedulingFields(),
                            fn (array $data) => JobForm::syncRequestedDaytime($data),
                        ),
                    ])
                    ->columns(3)
                    ->schema([
                        TextEntry::make('scheduled_at')->label('Scheduled (Pacific)')
                            ->state(fn ($record) => $record->scheduled_at
                                ? Timezone::toDisplay($record->scheduled_at)->format('D, M j, Y g:i A') : '—'),
                        TextEntry::make('scheduled_exact_time')->label('Scheduled time')
                            ->visible(fn ($record) => $record->scheduled_at !== null)
                            ->badge()
                            ->color(fn ($record) => $record->scheduled_exact_time ? 'warning' : 'gray')
                            ->state(fn ($record) => $record->scheduled_exact_time
                                ? TemplateRenderer::formatWindow($record).' (exact)'
                                : TemplateRenderer::formatWindow($record)),
                        TextEntry::make('technician.name')->label('Technician')->placeholder('—'),
                        TextEntry::make('service_address')->label('Service address')->placeholder('—')
                            ->copyable()->columnSpanFull(),
                        TextEntry::make('warehouse.name')->label('Warehouse')
                            ->visible(fn ($record) => ! $record->isLaborOnly())
                            ->state(fn ($record) => $record->warehouse?->name ?? $record->warehouse_other ?? '—'),
                        TextEntry::make('requested_day')->label('Requested day')
                            ->placeholder('—')
                            // Fall back to the legacy combined string for old jobs.
                            ->state(fn ($record) => JobForm::formatRequestedDay($record->requested_day)
                                ?? $record->requested_daytime_raw ?? '—'),
                        // The preference's own wording ("After 10am", "Morning"),
                        // falling back to the free text a specific/legacy row carries.
                        TextEntry::make('requested_time')->label('Requested time')->placeholder('—')
                            ->state(fn (Job $record) => $record->requestedTimeLabel() ?: '—'),
                        TextEntry::make('rescheduled_count')->label('Times rescheduled'),
                        // Only shown while a "Schedule w/ AI" negotiation is actually
                        // in flight (running or paused) — once it resolves, the normal
                        // scheduling fields above tell the whole story.
                        TextEntry::make('ai_scheduling_status')->label('AI scheduling')
                            ->visible(fn (Job $record) => $record->liveAiSchedulingSession() !== null)
                            ->badge()
                            ->color(fn (Job $record) => $record->liveAiSchedulingSession()?->status === 'paused' ? 'warning' : 'info')
                            ->state(fn (Job $record) => 'Session '.($record->liveAiSchedulingSession()?->status ?? ''))
                            ->url(fn (Job $record) => AiScheduling::getUrl([
                                'selected' => $record->liveAiSchedulingSession()?->id,
                            ]))
                            ->openUrlInNewTab(),
                    ]),
                // ONE notes section: the intake note pinned first, then every note
                // logged since, as chronological bubbles. "Edit" edits the intake
                // note only — logged notes are an append-only trail.
                Section::make('Notes')
                    ->key('notes')
                    ->description('Oldest first. Edit or delete your own notes from the bubble.')
                    ->headerActions([
                        self::editAction(
                            'editNotes',
                            fn (Job $record) => $record->only(['notes']),
                            JobForm::notesFields(),
                        )->label('Edit intake note')->modalHeading('Edit intake note')
                            // Many jobs never get one — only offer the edit when there is one.
                            ->visible(fn (Job $record) => filled($record->notes)
                                && (auth()->user()?->can('manage_jobs') ?? false)),
                        JobActions::addNote(),
                    ])
                    ->schema([
                        ViewEntry::make('notes_feed')->hiddenLabel()
                            ->view('filament.jobs.notes-feed')
                            ->columnSpanFull(),
                    ]),
                Section::make('Parse')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('parse_status')->badge(),
                        TextEntry::make('parse_warnings_json')->label('Warnings')
                            ->state(fn ($record) => collect($record->parse_warnings_json ?? [])->implode("\n"))
                            ->placeholder('None'),
                        TextEntry::make('intake_raw_text')->label('Original text')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Timeline')
                    ->schema([
                        TextEntry::make('timeline')->hiddenLabel()
                            ->state(fn ($record) => JobTimeline::html($record))->html(),
                    ]),
            ]);
    }

    /**
     * Build a per-section "Edit" modal action for the job view. Edits only that
     * section's fields and saves inline (no full-page edit/refresh).
     *
     * @param  \Closure(Job): array  $fill  current values for the modal form
     * @param  array<int, mixed>  $schema  the section's editable fields
     * @param  ?\Closure(array): array  $transform  optional state->columns mapping before save
     */
    private static function editAction(string $name, \Closure $fill, array $schema, ?\Closure $transform = null): Action
    {
        return Action::make($name)
            ->label('Edit')
            ->icon('heroicon-m-pencil-square')
            ->link()
            ->color('gray')
            ->modalHeading('Edit')
            ->visible(fn () => auth()->user()?->can('manage_jobs') ?? false)
            ->fillForm($fill)
            ->schema($schema)
            ->action(function (array $data, Job $record) use ($transform) {
                $record->update($transform ? $transform($data) : $data);
                Notification::make()->title('Saved')->success()->send();
            });
    }

    /** One line per part: "FW04239 GTY FYG — $155.00  ✓ tech paid". Falls back to part_number. */
    private static function partsSummary($record): string
    {
        $parts = collect($record->parts_json ?? [])
            ->map(function (array $p): ?string {
                $label = trim((string) ($p['label'] ?? ''));
                $cost = isset($p['cost_cents']) && $p['cost_cents'] !== null ? Money::format($p['cost_cents']) : null;
                $flag = ! empty($p['tech_paid']) ? '  ✓ tech paid' : '';

                return match (true) {
                    $label !== '' && $cost !== null => "{$label} — {$cost}{$flag}",
                    $label !== '' => $label.$flag,
                    $cost !== null => $cost.$flag,
                    default => null,
                };
            })
            ->filter()
            ->implode("\n");

        return $parts !== '' ? $parts : (string) ($record->part_number ?? '');
    }

    /** One line per payment collected: date · method · amount. */
    private static function paymentsSummary($record): string
    {
        return $record->payments
            ->map(fn ($p) => Timezone::toDisplay($p->collected_at)->format('M j').' · '
                .($p->method?->label() ?? '—').' · '.Money::format((int) $p->amount_cents))
            ->implode("\n");
    }
}
