<?php

namespace App\Filament\Resources\ScheduledMessages\Pages;

use App\Filament\Resources\ScheduledMessages\ScheduledMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListScheduledMessages extends ListRecords
{
    protected static string $resource = ScheduledMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
