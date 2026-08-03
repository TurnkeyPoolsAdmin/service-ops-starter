<?php

namespace App\Filament\Pages;

use App\Enums\Audience;
use App\Enums\JobFunction;
use App\Enums\JobStatus;
use App\Enums\Language;
use App\Enums\MessageType;
use App\Enums\TemplateKey;
use App\Filament\Resources\Jobs\JobResource;
use App\Filament\Resources\Jobs\Support\JobActions;
use App\Models\Employee;
use App\Models\Job;
use App\Models\Setting;
use App\Services\AiScheduling\AiSchedulingService;
use App\Services\JobLifecycle;
use App\Services\Messaging\TemplateRenderer;
use App\Services\Podium\PodiumThreadReader;
use App\Support\Money;
use App\Support\PhoneNumber;
use App\Support\RequestedDay;
use App\Support\Timezone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The step between intake and the job view: the dispatcher decides which
 * welcome to send. The choice is manual (the tool does NOT yet infer whether
 * sales handed the customer off) and drives JobLifecycle::sendWelcome():
 *
 *  - "Sales already handed off" → one dispatch intro from the Quo number.
 *  - "Sales has NOT handed off" → a handoff note from the Podium sales thread,
 *    then the dispatch intro from Quo ~45s later.
 *  - "CX not in Podium" → same as "Sales already handed off" (one dispatch
 *    intro from Quo); used when there's no Podium thread to hand off from.
 *
 * Reached only by redirect (?job=ID) from intake, so it stays out of the nav.
 */
class SendWelcome extends Page
{
    protected string $view = 'filament.pages.send-welcome';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Send welcome';

    public ?Job $jobRecord = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('intake_leads') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(?int $job = null): void
    {
        abort_unless(static::canAccess(), 403);

        // ?job=ID query param on the real page; mount arg in Livewire tests.
        $this->jobRecord = Job::with(['customer', 'entity'])->findOrFail($job ?? request()->integer('job'));
    }

    public function getTitle(): string
    {
        return "Send welcome — {$this->jobRecord->job_number}";
    }

    protected function getHeaderActions(): array
    {
        // Once a welcome exists for this job, what's left is texting techs for
        // tentative availability (whenever the location has techs) and moving on.
        if ($this->welcomeAlreadyQueued()) {
            return array_values(array_filter([
                // The scheduling ask is the natural next text after the intro:
                // it's what actually gets a time on the board. Dispatcher-
                // pressed, and only shown when there's one to send.
                JobActions::schedulingAskFor($this->jobRecord) !== null
                    ? JobActions::sendSchedulingAsk()->record($this->jobRecord)
                    : null,
                $this->canTextTechs() ? $this->techAvailabilityAction() : null,
                $this->canScheduleWithAi() ? JobActions::scheduleWithAi()->record($this->jobRecord) : null,
                Action::make('goToJob')
                    ->label('Go to job')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->url(fn () => JobResource::getUrl('view', ['record' => $this->jobRecord])),
            ]));
        }

        return [
            Action::make('salesAcknowledged')
                ->label('Podium exists')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Sales already handed off — send dispatch intro')
                ->modalDescription('One text, from our Quo number. Use this when sales has already told the customer we\'re taking over.')
                ->action(fn () => $this->dispatchWelcome(salesAcknowledged: true)),

            Action::make('salesNotAcknowledged')
                ->label('Podium handoff doesn\'t exist')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Sales has NOT handed off — send handoff, then intro')
                ->modalDescription('A handoff note goes out FROM the Podium sales thread now, then our dispatch intro from the Quo number ~45s later.')
                ->action(fn () => $this->dispatchWelcome(salesAcknowledged: false)),

            Action::make('customerNotInPodium')
                ->label('CX not in Podium')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Customer isn\'t in Podium — send dispatch intro')
                ->modalDescription('One text, from our Quo number. Use this when the customer isn\'t in Podium, so there\'s no sales thread to hand off from.')
                ->action(fn () => $this->dispatchWelcome(salesAcknowledged: true)),

            Action::make('skip')
                ->label('Skip')
                ->icon(Heroicon::OutlinedXMark)
                ->color('gray')
                ->link()
                ->requiresConfirmation()
                ->modalHeading('Skip — no welcome')
                ->modalDescription('No welcome text will be sent. You can still message the customer manually.')
                ->action(function () {
                    Notification::make()->title('Skipped — no welcome sent')->send();

                    return redirect(JobResource::getUrl('view', ['record' => $this->jobRecord]));
                }),
        ];
    }

    private function dispatchWelcome(bool $salesAcknowledged): mixed
    {
        app(JobLifecycle::class)->sendWelcome($this->jobRecord, $salesAcknowledged, auth()->user());

        Notification::make()
            ->title('Welcome queued')
            ->body($salesAcknowledged
                ? 'Dispatch intro queued from the Quo number.'
                : 'Podium handoff queued now; dispatch intro follows ~45s later.')
            ->success()
            ->send();

        // Come back to this page so the dispatcher can send the scheduling text
        // and/or ask techs for tentative availability right away. A fresh
        // request (not staying in-place) is required — header actions are
        // cached at request boot, so the buttons only show up on the next load.
        // With nothing left to do here, go straight to the job view.
        if ($this->canTextTechs() || JobActions::schedulingAskFor($this->jobRecord) !== null) {
            return redirect(static::getUrl(['job' => $this->jobRecord->id]));
        }

        return redirect(JobResource::getUrl('view', ['record' => $this->jobRecord]));
    }

    /** True when this job's location has techs to text for availability. */
    public function canTextTechs(): bool
    {
        return $this->locationTechOptions() !== [];
    }

    /** Mirrors JobActions::scheduleWithAi()'s own visibility so the button only shows when it would actually work. */
    public function canScheduleWithAi(): bool
    {
        return AiSchedulingService::enabled()
            && $this->jobRecord->status === JobStatus::PendingSchedule
            && AiSchedulingService::liveSessionFor($this->jobRecord) === null
            && Employee::active()->withJobFunction(JobFunction::Technician)
                ->where('ai_scheduling_enabled', true)
                ->whereIn('entity_id', $this->jobRecord->entity?->techPoolEntityIds() ?? array_filter([$this->jobRecord->entity_id]))
                ->exists();
    }

    /**
     * Active technicians attached to THIS job's entity (location) — LA and SFV
     * are treated as one pool since their crews cover both.
     */
    public function locationTechOptions(): array
    {
        return Employee::active()
            ->withJobFunction(JobFunction::Technician)
            ->whereIn('entity_id', $this->jobRecord->entity?->techPoolEntityIds() ?? array_filter([$this->jobRecord->entity_id]))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** The availability text exactly as the selected techs will receive it. */
    public function previewTechAvailability(): ?string
    {
        return $this->safeRender(TemplateKey::TechJobAvailability);
    }

    private function techAvailabilityAction(): Action
    {
        return Action::make('requestTechAvailability')
            ->label('Text techs for availability')
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->color('info')
            ->modalHeading('Text techs — tentative availability')
            ->modalDescription('Sends the Spanish availability request from the Quo number to the techs you pick. You\'ll still call to confirm — this just starts the conversation.')
            ->modalSubmitActionLabel('Send texts')
            ->schema([
                CheckboxList::make('tech_ids')
                    ->label('Technicians — '.($this->jobRecord->entity?->techPoolLabel() ?? 'this location'))
                    ->options($this->locationTechOptions())
                    ->required()
                    ->helperText(fn () => $this->previewTechAvailability()
                        ? "Each tech receives:\n\n".$this->previewTechAvailability()
                        : '⚠ Template missing/inactive — check Message Templates.'),
            ])
            ->action(function (array $data) {
                $count = app(JobLifecycle::class)->sendTechAvailabilityRequests(
                    $this->jobRecord, $data['tech_ids'] ?? [], auth()->user(),
                );

                Notification::make()
                    ->title($count > 0 ? "Texted {$count} tech(s)" : 'No techs texted')
                    ->body($count > 0 ? 'Availability requests queued from the Quo number.' : 'Check the selected techs have a valid phone.')
                    ->status($count > 0 ? 'success' : 'warning')
                    ->send();
            });
    }

    public function welcomeAlreadyQueued(): bool
    {
        return $this->jobRecord->scheduledMessages()
            ->where('message_type', MessageType::Welcome->value)
            ->exists();
    }

    /** Outgoing texts globally paused (build mode) — show a heads-up banner. */
    public function textsPaused(): bool
    {
        return app()->environment('production') && Setting::get('outgoing_sms_paused') === '1';
    }

    /** Last few Podium messages for the customer, oldest->newest, for context. */
    public function podiumThread(): array
    {
        return app(PodiumThreadReader::class)->latestFor($this->jobRecord->customer?->phone_e164, 5);
    }

    /**
     * Everything the dispatcher would otherwise open the job to read, as an
     * ordered label => value map for the details panel at the bottom of the
     * page. Shows for EVERY job, manual or auto-intaken — a lead the sales
     * dashboard pushed in was never typed by anyone here, so this screen is the
     * first look anybody gets at it.
     *
     * Empty values are kept (rendered as a dash) so the shape of the panel is
     * the same every time and a missing field is visibly missing.
     *
     * @return array<string, ?string>
     */
    public function jobDetails(): array
    {
        $job = $this->jobRecord;

        return [
            'Job number' => $job->job_number,
            'Entity' => $job->entity?->name,
            'Customer' => $job->customer?->full_name ?: $job->customer_name_snapshot,
            'Phone' => PhoneNumber::toDisplay($job->customer?->phone_e164 ?: $job->customer_phone_snapshot),
            'Address' => $job->fullServiceAddress(),
            'Vehicle' => trim(implode(' ', array_filter([
                $job->vehicle_year, $job->vehicle_make, $job->vehicle_model,
            ]))) ?: $job->vehicle_raw,
            'Glass position' => $job->glass_position,
            'Part number' => $job->part_number,
            'Part available' => match ($job->part_available) {
                true => 'Yes',
                false => 'No',
                default => 'Not confirmed',
            },
            'Quote' => $job->quote_amount_cents !== null ? Money::format($job->quote_amount_cents) : null,
            'Insurance' => $job->is_insurance
                ? 'Yes'.(filled($job->insurance_carrier) ? ' - '.$job->insurance_carrier : '')
                : 'No',
            'Requested day/time' => $job->requested_daytime_raw
                ?: trim((string) RequestedDay::spellOut($job->requested_day).' '.$job->requestedTimeLabel()),
            'Salesperson' => $job->bookingSalespersonName(),
            'Notes' => $job->notes,
        ];
    }

    public function previewDispatchIntro(): ?string
    {
        // Show the variant that will actually fire right now (business-hours
        // default vs. after-hours / weekend).
        return $this->safeRender(TemplateKey::welcomeDispatchIntroFor(Timezone::now()));
    }

    public function previewPodiumHandoff(): ?string
    {
        return $this->safeRender(TemplateKey::WelcomePodiumHandoff);
    }

    /**
     * Render a template as its recipient will read it: customer texts in the
     * customer's language, tech texts in the crew's default (Spanish). Null
     * when the template is missing/inactive or a required var is empty.
     */
    private function safeRender(TemplateKey $key): ?string
    {
        try {
            $language = $key->audience() === Audience::Tech
                ? Language::Spanish
                : $this->jobRecord->customer?->preferredLanguage();

            return app(TemplateRenderer::class)
                ->renderForJob($key, $this->jobRecord, auth()->user()?->name, language: $language);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
