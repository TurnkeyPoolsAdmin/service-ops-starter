<?php

namespace App\Filament\Resources\InboundMessages\Pages;

use App\Filament\Resources\InboundMessages\InboundMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListInboundMessages extends ListRecords
{
    protected static string $resource = InboundMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
