<?php

namespace App\Filament\Resources\WarrantyClaims\Pages;

use App\Filament\Resources\WarrantyClaims\WarrantyClaimResource;
use App\Models\WarrantyClaim;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListWarrantyClaims extends ListRecords
{
    protected static string $resource = WarrantyClaimResource::class;

    protected function getHeaderActions(): array
    {
        // Claims are opened from a job's "Warranty / fix needed" action — not here.
        return [];
    }

    public function getTabs(): array
    {
        return [
            'needs_scheduling' => Tab::make('Needs scheduling')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', WarrantyClaim::STATUS_OPEN))
                ->badge(WarrantyClaim::needsScheduling()->count() ?: null)
                ->badgeColor('warning'),

            'scheduled' => Tab::make('Visit scheduled')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', WarrantyClaim::STATUS_SCHEDULED))
                ->badge(WarrantyClaim::where('status', WarrantyClaim::STATUS_SCHEDULED)->count() ?: null),

            'resolved' => Tab::make('Resolved')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', WarrantyClaim::STATUS_RESOLVED)),

            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'needs_scheduling';
    }
}
