<?php

namespace App\Filament\Resources\ScheduledMessages\Tables;

use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Filament\Resources\Jobs\JobResource;
use App\Jobs\SendSmsJob;
use App\Models\ScheduledMessage;
use App\Support\Timezone;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ScheduledMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Outbox shows only pending work (see ScheduledMessageResource::
            // getEloquentQuery) — upcoming/queued sends and failed ones to retry.
            ->defaultSort('scheduled_for', 'asc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('job.job_number')->label('Job')->searchable()->url(
                    fn (ScheduledMessage $r) => $r->job_id
                        ? JobResource::getUrl('view', ['record' => $r->job_id])
                        : null,
                ),
                TextColumn::make('message_type')->badge()
                    ->formatStateUsing(fn (MessageType $state) => $state->label()),
                TextColumn::make('template_key')->toggleable()
                    ->formatStateUsing(fn ($state) => $state?->value),
                TextColumn::make('to_phone_e164')->label('To')->searchable(),
                TextColumn::make('rendered_body')->label('Message')
                    ->limit(50)->wrap()->tooltip(fn (ScheduledMessage $r) => $r->rendered_body)
                    ->searchable()->toggleable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (MessageStatus $state) => $state->label())
                    ->color(fn (MessageStatus $state) => $state->color()),
                TextColumn::make('scheduled_for')->label('Scheduled for')
                    ->dateTime('M j, g:i A')->timezone(Timezone::display())->placeholder('immediate')->sortable(),
                TextColumn::make('attempt_count')->label('Attempts')->alignCenter(),
                TextColumn::make('sent_at')->dateTime('M j, g:i A')->timezone(Timezone::display())->placeholder('—')->toggleable(),
                TextColumn::make('error_message')->limit(40)->placeholder('—')->toggleable()->color('danger'),
                TextColumn::make('skip_reason')->limit(30)->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        MessageStatus::Queued->value => MessageStatus::Queued->label(),
                        MessageStatus::Processing->value => MessageStatus::Processing->label(),
                        MessageStatus::Failed->value => MessageStatus::Failed->label(),
                    ]),
                SelectFilter::make('message_type')
                    ->options(collect(MessageType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
                Filter::make('scheduled_for')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $q, array $data) {
                        return $q
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('scheduled_for', '>=', $d))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('scheduled_for', '<=', $d));
                    }),
            ])
            ->recordActions([
                // Customize a not-yet-sent message: edit the body (and optionally
                // its send time) before it goes out. Only unsent rows are editable.
                Action::make('editMessage')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (ScheduledMessage $r) => $r->isUnsent())
                    ->fillForm(fn (ScheduledMessage $r) => [
                        'rendered_body' => $r->rendered_body,
                        'scheduled_for' => $r->scheduled_for,
                    ])
                    ->schema([
                        Textarea::make('rendered_body')->label('Message')->rows(5)->required(),
                        DateTimePicker::make('scheduled_for')->label('Send at (Pacific)')
                            ->timezone(Timezone::display())->seconds(false)
                            ->helperText('Leave blank to send on the next processor run.'),
                    ])
                    ->action(function (ScheduledMessage $r, array $data) {
                        $r->update([
                            'rendered_body' => $data['rendered_body'],
                            'scheduled_for' => $data['scheduled_for'],
                        ]);
                        Notification::make()->title('Message updated')->success()->send();
                    }),
                Action::make('retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (ScheduledMessage $r) => $r->status === MessageStatus::Failed)
                    ->action(function (ScheduledMessage $r) {
                        $r->update([
                            'status' => MessageStatus::Queued->value,
                            'attempt_count' => 0,
                            'scheduled_for' => now(),
                            'error_code' => null,
                            'error_message' => null,
                            'failed_at' => null,
                        ]);
                        SendSmsJob::dispatch($r->id)->afterCommit();
                        Notification::make()->title('Message re-queued')->success()->send();
                    }),
            ]);
    }
}
