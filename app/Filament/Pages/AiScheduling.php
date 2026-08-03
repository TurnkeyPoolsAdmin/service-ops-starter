<?php

namespace App\Filament\Pages;

use App\Enums\Language;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Jobs\JobResource;
use App\Filament\Support\AiSchedulingTranscript;
use App\Models\AiSchedulingSession;
use App\Models\AiSchedulingTurn;
use App\Models\Customer;
use App\Models\Employee;
use App\Services\AiScheduling\AiSchedulingService;
use App\Support\Timezone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * "Schedule w/ AI" session monitor — a list of sessions plus a transcript
 * panel for whichever one is selected. Built as one Page (list + detail pane
 * in the same Livewire component, per §5.2) rather than a table row's modal,
 * so the transcript stays visible while stepping through simulate-mode
 * replies instead of having to reopen a modal after every one.
 *
 * See docs/ai-scheduling-spec.md §5.2 for the intended shape of this page;
 * this is the internal-testing-first cut of it (§10 rollout stage 1).
 */
class AiScheduling extends Page implements HasTable
{
    use GatedByPermission;
    use InteractsWithTable;

    protected static function requiredPermission(): string
    {
        return 'manage_jobs';
    }

    protected string $view = 'filament.pages.ai-scheduling';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'AI Scheduling';

    protected static ?string $navigationLabel = 'AI Scheduling';

    protected static ?int $navigationSort = 7;

    /** The session whose transcript is shown below the list, if any. */
    #[Url]
    public ?int $selected = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AiSchedulingSession::query()
                ->with(['job.customer', 'resolvedTechnician', 'currentAttempt.technician']))
            ->columns([
                TextColumn::make('job.job_number')->label('Job')
                    ->url(fn (AiSchedulingSession $r) => $r->job ? JobResource::getUrl('view', ['record' => $r->job]) : null)
                    ->openUrlInNewTab(),
                TextColumn::make('job.customer.full_name')->label('Customer')->placeholder('—'),
                TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('target')->label('Target')
                    ->state(fn (AiSchedulingSession $r) => $r->constraintObject()->phrase(Language::English)),
                TextColumn::make('tech')->label('Current tech')
                    ->state(fn (AiSchedulingSession $r) => $r->currentAttempt?->technician?->name
                        ?? $r->resolvedTechnician?->name ?? '—'),
                TextColumn::make('tech_mode')->label('Mode')->badge()->color('gray'),
                TextColumn::make('cost_cents_micro')->label('Cost')
                    ->state(fn (AiSchedulingSession $r) => $r->cost_cents_micro > 0
                        ? '$'.number_format($r->cost_cents_micro / 100_000_000, 4) : '—'),
                TextColumn::make('created_at')->label('Started')
                    ->dateTime('M j, g:ia')->timezone(Timezone::display()),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->action(fn (AiSchedulingSession $record) => $this->selected = $record->id),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No AI scheduling sessions yet')
            ->emptyStateDescription('Start one from a job\'s "Schedule w/ AI" action.')
            ->emptyStateIcon(Heroicon::OutlinedSparkles);
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            AiSchedulingSession::STATUS_RUNNING => 'info',
            AiSchedulingSession::STATUS_PAUSED => 'warning',
            AiSchedulingSession::STATUS_SCHEDULED => 'success',
            AiSchedulingSession::STATUS_SIMULATED => 'success',
            AiSchedulingSession::STATUS_EXHAUSTED, AiSchedulingSession::STATUS_CANCELED => 'danger',
            default => 'gray',
        };
    }

    public function selectedSession(): ?AiSchedulingSession
    {
        if ($this->selected === null) {
            return null;
        }

        return AiSchedulingSession::query()
            ->with(['job.customer', 'job.entity', 'attempts.technician', 'resolvedTechnician'])
            ->find($this->selected);
    }

    /** @return array<int, array<string, mixed>> */
    public function bubbles(): array
    {
        $session = $this->selectedSession();

        return $session ? AiSchedulingTranscript::bubbles($session) : [];
    }

    // ------------------------------------------------------------- controls

    public function pauseSessionAction(): Action
    {
        return Action::make('pauseSession')
            ->label('Pause')
            ->icon(Heroicon::OutlinedPause)
            ->color('warning')
            ->schema([
                TextInput::make('reason')->label('Reason')->required()
                    ->placeholder('e.g. need to call the customer first'),
            ])
            ->action(function (array $data) {
                $this->withSelected(fn ($session) => app(AiSchedulingService::class)->pause($session, $data['reason']));
            });
    }

    public function resumeSessionAction(): Action
    {
        return Action::make('resumeSession')
            ->label('Resume')
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->action(function () {
                $this->withSelected(fn ($session) => app(AiSchedulingService::class)->resume($session));
            });
    }

    public function skipTechAction(): Action
    {
        return Action::make('skipTech')
            ->label('Skip this tech')
            ->icon(Heroicon::OutlinedForward)
            ->color('gray')
            ->schema([
                TextInput::make('reason')->label('Reason (optional)'),
            ])
            ->action(function (array $data) {
                $this->withSelected(fn ($session) => app(AiSchedulingService::class)
                    ->skipTech($session, $data['reason'] ?: 'skipped by a dispatcher'));
            });
    }

    public function cancelSessionAction(): Action
    {
        return Action::make('cancelSession')
            ->label('Cancel')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Stops this session for good. It won\'t contact any more technicians.')
            ->schema([
                TextInput::make('reason')->label('Reason (optional)'),
            ])
            ->action(function (array $data) {
                $this->withSelected(fn ($session) => app(AiSchedulingService::class)
                    ->cancel($session, $data['reason'] ?: 'canceled by a dispatcher'));
            });
    }

    /** Cancels the session and hands the job back for a human to schedule the normal way. */
    public function takeOverAction(): Action
    {
        return Action::make('takeOver')
            ->label('Take over')
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Cancels this AI session. You\'ll land on the job to schedule it yourself using the normal Schedule action.')
            ->action(function () {
                $session = $this->selectedSession();
                if ($session === null) {
                    return null;
                }

                $job = $session->job;
                app(AiSchedulingService::class)->cancel($session, 'taken over by a dispatcher');

                return redirect(JobResource::getUrl('view', ['record' => $job]));
            });
    }

    /** Send an outbound that "approve each" mode parked, per the pending turn's own recipient. */
    public function approveTurnAction(): Action
    {
        return Action::make('approveTurn')
            ->label('Send')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('success')
            ->action(function (array $arguments) {
                $turn = AiSchedulingTurn::find($arguments['turn'] ?? null);
                if ($turn !== null) {
                    app(AiSchedulingService::class)->approveOutbound($turn);
                }
            });
    }

    public function skipTurnAction(): Action
    {
        return Action::make('skipTurn')
            ->label("Don't send")
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->requiresConfirmation()
            ->action(function (array $arguments) {
                $turn = AiSchedulingTurn::find($arguments['turn'] ?? null);
                if ($turn !== null && $turn->isAwaitingApproval()) {
                    $turn->update(['approval_status' => AiSchedulingTurn::APPROVAL_SKIPPED]);
                }
            });
    }

    // -------------------------------------------------------- simulate console

    /**
     * Everyone this session could plausibly hear from: every tech it has ever
     * contacted (not just the current one — a superseded tech's late reply is
     * exactly the kind of thing worth simulating) plus the job's customer.
     *
     * @return array<string, string>
     */
    public function simulateParticipantOptions(): array
    {
        $session = $this->selectedSession();
        if ($session === null) {
            return [];
        }

        $options = [];
        foreach ($session->attempts as $attempt) {
            if ($attempt->technician !== null) {
                $options['tech:'.$attempt->employee_id] = $attempt->technician->name.' (tech)';
            }
        }

        $customer = $session->job?->customer;
        if ($customer !== null) {
            $options['customer:'.$customer->id] = $customer->full_name.' (customer)';
        }

        return $options;
    }

    public function simulateReplyAction(): Action
    {
        return Action::make('simulateReply')
            ->label('Simulate a reply')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->color('primary')
            ->modalHeading('Type a reply as if it arrived by SMS')
            ->modalDescription('Runs the real negotiation logic and the real classifier. Nothing is sent to anyone.')
            ->schema([
                Select::make('participant')->label('From')
                    ->options(fn () => $this->simulateParticipantOptions())
                    ->required(),
                Textarea::make('body')->label('Message')->rows(2)->required(),
            ])
            ->action(function (array $data) {
                $session = $this->selectedSession();
                if ($session === null || ! str_contains($data['participant'] ?? '', ':')) {
                    return;
                }

                [$type, $id] = explode(':', $data['participant'], 2);
                $service = app(AiSchedulingService::class);

                if ($type === 'tech') {
                    $tech = Employee::find($id);
                    if ($tech !== null) {
                        $service->simulateTechReply($session, $tech, $data['body']);
                    }
                } elseif ($type === 'customer') {
                    $customer = Customer::find($id);
                    if ($customer !== null) {
                        $service->simulateCustomerReply($session, $customer, $data['body']);
                    }
                }
            });
    }

    private function withSelected(callable $callback): void
    {
        $session = $this->selectedSession();
        if ($session === null) {
            Notification::make()->title('Select a session first')->warning()->send();

            return;
        }

        $callback($session);
    }
}
