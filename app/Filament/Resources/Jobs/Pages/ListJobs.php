<?php

namespace App\Filament\Resources\Jobs\Pages;

use App\Filament\Resources\Jobs\JobResource;
use App\Models\Job;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListJobs extends ListRecords
{
    protected static string $resource = JobResource::class;

    protected function getHeaderActions(): array
    {
        // Jobs are created via the Intake page; no create button here.
        return [];
    }

    /**
     * Exception queues — the highest-value view of the board. Each tab is a
     * named scope on the Job model so the operator can triage by what needs
     * doing rather than by raw status.
     */
    public function getTabs(): array
    {
        return [
            'needs_scheduling' => Tab::make('Needs scheduling')
                ->modifyQueryUsing(fn (Builder $query) => $query->needsScheduling())
                ->badge(Job::needsScheduling()->count()),

            // Still unscheduled, but parked waiting on the customer — kept out of
            // "Needs scheduling" (Job::scopeNeedsScheduling) so that tab stays a
            // list of work somebody can actually do today.
            'on_hold' => Tab::make('On hold')
                ->modifyQueryUsing(fn (Builder $query) => $query->onSchedulingHold())
                ->badge(Job::onSchedulingHold()->count() ?: null)
                ->badgeColor('warning'),

            'scheduled' => Tab::make('Scheduled')
                ->modifyQueryUsing(fn (Builder $query) => $query->scheduled())
                ->badge(Job::scheduled()->count()),

            'needs_tech' => Tab::make('Needs tech assigned')
                ->modifyQueryUsing(fn (Builder $query) => $query->needsTechAssigned())
                ->badge(Job::needsTechAssigned()->count())
                ->badgeColor('warning'),

            'awaiting_payment' => Tab::make('Work done — awaiting payment')
                ->modifyQueryUsing(fn (Builder $query) => $query->awaitingPayment())
                ->badge(Job::awaitingPayment()->count()),

            'paid' => Tab::make('Paid')
                ->modifyQueryUsing(fn (Builder $query) => $query->paid())
                ->badge(Job::paid()->count()),

            'insurance' => Tab::make('Insurance')
                ->modifyQueryUsing(fn (Builder $query) => $query->insurance())
                ->badge(Job::insuranceUnpaid()->count())
                ->badgeColor('warning'),

            'failed_sms' => Tab::make('Failed SMS')
                ->modifyQueryUsing(fn (Builder $query) => $query->failedSms())
                ->badge(Job::failedSms()->count()),

            'canceled_lost' => Tab::make('Canceled / lost')
                ->modifyQueryUsing(fn (Builder $query) => $query->canceledOrLost())
                ->badge(Job::canceledOrLost()->count()),

            // Every other tab is a scope, so the board's search box only ever
            // searches WITHIN the open tab - a paid job is invisible while you
            // stand on "Needs scheduling". This tab is the unscoped one to
            // search from. It sits LAST so the board still opens on
            // "Needs scheduling"; no badge, because a total is not a queue.
            'all' => Tab::make('All jobs'),
        ];
    }
}
