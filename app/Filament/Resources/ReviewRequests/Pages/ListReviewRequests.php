<?php

namespace App\Filament\Resources\ReviewRequests\Pages;

use App\Filament\Resources\ReviewRequests\ReviewRequestResource;
use App\Models\ReviewRequest;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListReviewRequests extends ListRecords
{
    protected static string $resource = ReviewRequestResource::class;

    protected function getHeaderActions(): array
    {
        // Rows are created when work is marked complete — not here.
        return [];
    }

    public function getTabs(): array
    {
        return [
            'first_call_needed' => Tab::make('First call needed')
                ->modifyQueryUsing(fn (Builder $query) => $query->awaitingFirstCall())
                ->badge(ReviewRequest::awaitingFirstCall()->count())
                ->badgeColor('warning'),

            'call_needed' => Tab::make('Second call needed')
                ->modifyQueryUsing(fn (Builder $query) => $query->callNeeded())
                ->badge(ReviewRequest::callNeeded()->count())
                ->badgeColor('warning'),

            'in_progress' => Tab::make('In progress')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ReviewRequest::STATUS_ACTIVE))
                ->badge(ReviewRequest::where('status', ReviewRequest::STATUS_ACTIVE)->count()),

            'paused' => Tab::make('Paused')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ReviewRequest::STATUS_PAUSED))
                ->badge(ReviewRequest::where('status', ReviewRequest::STATUS_PAUSED)->count() ?: null),

            'reviews_left' => Tab::make('Reviews left')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ReviewRequest::STATUS_REVIEW_LEFT))
                ->badge(ReviewRequest::where('status', ReviewRequest::STATUS_REVIEW_LEFT)->count() ?: null)
                ->badgeColor('success'),

            'done' => Tab::make('Done')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    ReviewRequest::STATUS_COMPLETED, ReviewRequest::STATUS_CANCELED,
                ])),

            'all' => Tab::make('All'),
        ];
    }

    /** Land on the workflow tab only when there's a call to make. */
    public function getDefaultActiveTab(): string
    {
        return match (true) {
            ReviewRequest::awaitingFirstCall()->exists() => 'first_call_needed',
            ReviewRequest::callNeeded()->exists() => 'call_needed',
            default => 'in_progress',
        };
    }
}
