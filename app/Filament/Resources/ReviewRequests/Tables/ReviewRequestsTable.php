<?php

namespace App\Filament\Resources\ReviewRequests\Tables;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\ReviewRequest;
use App\Services\Reviews\ReviewDripService;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReviewRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('job.job_number')
                    ->label('Job')
                    ->url(fn (ReviewRequest $record) => $record->job
                        ? JobResource::getUrl('view', ['record' => $record->job]) : null)
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('job.customer_name_snapshot')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('customer.phone_e164')
                    ->label('Phone')
                    ->url(fn (ReviewRequest $record) => $record->customer?->quo_conversation_url, shouldOpenInNewTab: true)
                    ->color(fn (ReviewRequest $record) => filled($record->customer?->quo_conversation_url) ? 'primary' : null)
                    ->searchable(),
                TextColumn::make('entity.code')
                    ->label('Loc')
                    ->badge(),
                TextColumn::make('stage')
                    ->label('Step')
                    ->state(fn (ReviewRequest $record) => $record->stageLabel())
                    ->badge()
                    ->color(fn (ReviewRequest $record) => $record->stageColor()),
                TextColumn::make('progress')
                    ->label('Texts sent')
                    ->state(fn (ReviewRequest $record) => self::progressLine($record))
                    ->color('gray'),
            ])
            ->recordActions([
                self::callNowAction(),
                self::logFirstCallAction(),
                self::logCallAction(),
                self::reviewLeftAction(),
                self::pauseAction(),
                self::resumeAction(),
                self::cancelAction(),
            ]);
    }

    /** Quick "Call" deep-link into the customer's Quo conversation. */
    private static function callNowAction(): Action
    {
        return Action::make('callNow')
            ->label('Call')
            ->icon(Heroicon::OutlinedPhone)
            ->color('primary')
            ->visible(fn (ReviewRequest $record) => $record->status === ReviewRequest::STATUS_AWAITING_CALL
                && filled($record->customer?->quo_conversation_url))
            ->url(fn (ReviewRequest $record) => $record->customer?->quo_conversation_url, shouldOpenInNewTab: true);
    }

    /**
     * The first call — right after completion. Connected/no-answer both
     * require the thumbs up/down (down skips the drip entirely); "skip for
     * later" leaves the row awaiting_call for the Telegram nag / 7pm
     * fallthrough to pick up.
     */
    private static function logFirstCallAction(): Action
    {
        return Action::make('logFirstCall')
            ->label('Log call')
            ->icon(Heroicon::OutlinedPhone)
            ->color('warning')
            ->visible(fn (ReviewRequest $record) => $record->status === ReviewRequest::STATUS_AWAITING_CALL)
            ->modalHeading(fn (ReviewRequest $record) => 'Log the first review call — '
                .($record->job?->customer_name_snapshot ?: 'customer'))
            ->schema([
                Radio::make('outcome')
                    ->label('How did the call go?')
                    ->options([
                        ReviewRequest::FIRST_CALL_CONNECTED => 'Connected — spoke with the customer',
                        ReviewRequest::FIRST_CALL_NO_ANSWER => 'No answer',
                        'skip' => 'Skip for later',
                    ])
                    ->required()
                    ->live(),
                Radio::make('job_sentiment')
                    ->label('How did the job go?')
                    ->options([
                        'up' => '👍 Went well — start review request texts',
                        'down' => '👎 Not great — skip the review requests',
                    ])
                    ->visible(fn (Get $get) => in_array($get('outcome'), [
                        ReviewRequest::FIRST_CALL_CONNECTED, ReviewRequest::FIRST_CALL_NO_ANSWER,
                    ], true))
                    ->required(fn (Get $get) => in_array($get('outcome'), [
                        ReviewRequest::FIRST_CALL_CONNECTED, ReviewRequest::FIRST_CALL_NO_ANSWER,
                    ], true)),
            ])
            ->action(function (ReviewRequest $record, array $data) {
                $outcome = $data['outcome'];
                $wentWell = $outcome === 'skip' ? null : ($data['job_sentiment'] ?? null) === 'up';

                self::drips()->logFirstCall($record, $outcome, $wentWell, auth()->user());

                $body = match (true) {
                    $outcome === 'skip' => 'Left for later — it\'ll keep nagging until logged or the 7pm deadline.',
                    $wentWell === false => 'Marked skipped — no review texts will be sent.',
                    $outcome === ReviewRequest::FIRST_CALL_CONNECTED => 'Review text is going out now.',
                    default => 'Review text is going out now.',
                };
                Notification::make()->title('Call logged')->body($body)->success()->send();
            });
    }

    /** Compact "call1 ✓ · 1 ✓ · 2 ✓ · call2 ✓ · 3 ✓ · final ✓" trail of what's gone out. */
    private static function progressLine(ReviewRequest $r): string
    {
        $mark = fn (?object $at) => $at !== null ? '✓' : '·';

        $call1 = $r->first_call_outcome === null ? '·'
            : ($r->first_call_outcome === ReviewRequest::FIRST_CALL_AUTO ? 'AUTO' : '✓');
        $call2 = $r->call_outcome === null ? '·'
            : ($r->call_outcome === ReviewRequest::OUTCOME_VOICEMAIL ? 'VM' : '✓');

        return 'call1 '.$call1
            .'  1 '.$mark($r->step1_sent_at)
            .'  2 '.$mark($r->step2_sent_at)
            .'  call2 '.$call2
            .'  3 '.$mark($r->step3_sent_at)
            .'  final '.$mark($r->final_sent_at);
    }

    private static function drips(): ReviewDripService
    {
        return app(ReviewDripService::class);
    }

    /**
     * The manual call after text 2. Voicemail → template 3 goes out now;
     * connected → dispatch picks "send the after-call text" or "not interested"
     * (which removes the customer from the queue).
     */
    private static function logCallAction(): Action
    {
        return Action::make('logCall')
            ->label('Log call')
            ->icon(Heroicon::OutlinedPhone)
            ->color('warning')
            ->visible(fn (ReviewRequest $record) => $record->isCallNeeded())
            ->modalHeading(fn (ReviewRequest $record) => 'Log review call — '
                .($record->job?->customer_name_snapshot ?: 'customer'))
            ->schema([
                Radio::make('outcome')
                    ->label('How did the call go?')
                    ->options([
                        ReviewRequest::OUTCOME_CONNECTED => 'Connected — spoke with the customer',
                        ReviewRequest::OUTCOME_VOICEMAIL => 'Voicemail — left a message (sends review text 3)',
                    ])
                    ->required()
                    ->live(),
                Radio::make('connected_choice')
                    ->label('And then?')
                    ->options([
                        'send_text' => 'Send the after-call review text now',
                        'not_interested' => 'Not interested — remove from the review queue',
                    ])
                    ->visible(fn (Get $get) => $get('outcome') === ReviewRequest::OUTCOME_CONNECTED)
                    ->required(fn (Get $get) => $get('outcome') === ReviewRequest::OUTCOME_CONNECTED),
            ])
            ->action(function (ReviewRequest $record, array $data) {
                self::drips()->logCall(
                    $record,
                    $data['outcome'],
                    $data['connected_choice'] ?? null,
                    auth()->user(),
                );

                $body = match (true) {
                    $data['outcome'] === ReviewRequest::OUTCOME_VOICEMAIL => 'Review text 3 is going out; the final text follows in a week.',
                    ($data['connected_choice'] ?? null) === 'not_interested' => 'Removed from the review queue.',
                    default => 'After-call review text is going out; the final text follows in a week.',
                };
                Notification::make()->title('Call logged')->body($body)->success()->send();
            });
    }

    /** We spotted their review — send the thank-you and end the drip. */
    private static function reviewLeftAction(): Action
    {
        return Action::make('reviewLeft')
            ->label('Review left')
            ->icon(Heroicon::OutlinedStar)
            ->color('success')
            ->visible(fn (ReviewRequest $record) => $record->status !== ReviewRequest::STATUS_REVIEW_LEFT)
            ->requiresConfirmation()
            ->modalHeading('Customer left a review?')
            ->modalDescription('This stops any remaining review texts and sends the thank-you message.')
            ->modalSubmitActionLabel('Yes — send thank you')
            ->action(function (ReviewRequest $record) {
                self::drips()->markReviewLeft($record, auth()->user());
                Notification::make()->title('Marked as review left')
                    ->body($record->thank_you_sent_at
                        ? 'Thank-you text is going out.'
                        : 'Drip stopped. The thank-you text could not be sent (customer may be opted out).')
                    ->success()->send();
            });
    }

    private static function pauseAction(): Action
    {
        return Action::make('pause')
            ->label('Pause')
            ->icon(Heroicon::OutlinedPause)
            ->color('gray')
            ->visible(fn (ReviewRequest $record) => $record->status === ReviewRequest::STATUS_ACTIVE)
            ->requiresConfirmation()
            ->modalHeading('Pause this review drip?')
            ->modalDescription('No more review texts until you resume. It picks up right where it left off.')
            ->action(function (ReviewRequest $record) {
                self::drips()->pause($record, auth()->user());
                Notification::make()->title('Drip paused')->success()->send();
            });
    }

    private static function resumeAction(): Action
    {
        return Action::make('resume')
            ->label('Resume')
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->visible(fn (ReviewRequest $record) => $record->status === ReviewRequest::STATUS_PAUSED)
            ->action(function (ReviewRequest $record) {
                self::drips()->resume($record, auth()->user());
                Notification::make()->title('Drip resumed')
                    ->body('Any overdue step goes out within a few minutes.')->success()->send();
            });
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancelDrip')
            ->label('Cancel')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (ReviewRequest $record) => in_array($record->status, [
                ReviewRequest::STATUS_AWAITING_CALL, ReviewRequest::STATUS_ACTIVE, ReviewRequest::STATUS_PAUSED,
            ], true))
            ->schema([
                TextInput::make('reason')->label('Reason (optional)'),
            ])
            ->requiresConfirmation()
            ->modalHeading('Remove from the review queue?')
            ->modalDescription('No more review texts will be sent to this customer for this job.')
            ->action(function (ReviewRequest $record, array $data) {
                self::drips()->cancel($record, $data['reason'] ?: 'canceled from dashboard', auth()->user());
                Notification::make()->title('Drip canceled')->success()->send();
            });
    }
}
