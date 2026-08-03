<?php

namespace App\Filament\Widgets;

use App\Enums\JobStatus;
use App\Filament\Resources\Jobs\JobResource;
use App\Models\Job;
use App\Support\Timezone;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The day's run sheet, right on the dashboard: every job scheduled for today
 * (Pacific), in arrival order, one click from the job itself.
 */
class TodaySchedule extends TableWidget
{
    protected static ?int $sort = -24;

    public static function canView(): bool
    {
        return auth()->user()?->can('manage_jobs') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading("Today's schedule")
            ->description(Timezone::now()->format('l, F j'))
            ->query(
                Job::query()
                    ->scheduledToday()
                    ->whereNotIn('status', [JobStatus::Canceled->value, JobStatus::Lost->value])
                    ->orderBy('scheduled_at')
            )
            ->paginated(false)
            ->recordUrl(fn (Job $record) => JobResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('scheduled_at')->label('Time')
                    ->state(fn (Job $record) => self::windowLabel($record)),
                TextColumn::make('customer_name_snapshot')->label('Customer')
                    // Entity rides the vehicle line — a separate badge column
                    // doesn't fit this half-width table without clipping.
                    ->description(fn (Job $record) => trim(implode(' · ', array_filter([
                        trim(implode(' ', array_filter([
                            $record->vehicle_year, $record->vehicle_make, $record->vehicle_model,
                        ]))) ?: $record->vehicle_raw,
                        $record->entity?->code,
                    ])))),
                TextColumn::make('technician.name')->label('Tech')
                    // First name only — dispatch knows the crew, and the full
                    // name is what pushes Status off this half-width card.
                    ->formatStateUsing(fn (string $state) => str($state)->before(' ')->toString())
                    ->placeholder('—'),
                TextColumn::make('status')->badge()
                    // Short labels — this half-width table has no room to
                    // spare, and on a today-only run sheet "Booked"/"Done"
                    // say everything "Scheduled"/"Work completed" would.
                    ->formatStateUsing(fn (JobStatus $state) => match ($state) {
                        JobStatus::WorkCompleted => 'Done',
                        JobStatus::Scheduled => 'Booked',
                        default => $state->label(),
                    })
                    ->color(fn (JobStatus $state) => $state->color()),
            ])
            ->emptyStateHeading('Nothing scheduled today')
            ->emptyStateDescription('Jobs scheduled for today will line up here in arrival order.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }

    /**
     * Compact arrival label: "8–10 AM" for window jobs (minutes only when
     * they matter, e.g. "10:30 AM–12:30 PM"), "2:30 PM" for exact-time ones.
     */
    protected static function windowLabel(Job $job): string
    {
        $start = Timezone::toDisplay($job->scheduled_at);
        $compact = fn ($time) => $time->format((int) $time->format('i') === 0 ? 'g' : 'g:i');
        $meridiem = fn ($time) => $time->format('A');

        if ($job->scheduled_exact_time) {
            return $compact($start).' '.$meridiem($start);
        }

        $hours = $job->scheduled_window_hours
            ?: (int) config('glass.appointment_window_hours', 2);
        $end = $start->copy()->addHours($hours);

        return $meridiem($start) === $meridiem($end)
            ? $compact($start).'–'.$compact($end).' '.$meridiem($end)
            : $compact($start).' '.$meridiem($start).'–'.$compact($end).' '.$meridiem($end);
    }
}
