<?php

namespace App\Filament\Resources\Jobs\Pages;

use App\Filament\Resources\Jobs\JobResource;
use App\Filament\Resources\Jobs\Schemas\JobForm;
use App\Filament\Resources\Jobs\Support\JobActions;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditJob extends EditRecord
{
    protected static string $resource = JobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make(JobActions::all())->label('Actions')->button(),
            ViewAction::make(),
        ];
    }

    /** parts_json (+legacy fallback) -> the parts_input repeater rows (cost in cents). */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['parts_input'] = JobForm::toPartsInput($data);

        return $data;
    }

    /** parts_input repeater rows -> parts_json/part_number/part_cost_cents; sync requested string. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return JobForm::syncRequestedDaytime(JobForm::fromPartsInput($data));
    }
}
