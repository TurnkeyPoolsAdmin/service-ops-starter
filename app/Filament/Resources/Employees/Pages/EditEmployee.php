<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        // The super_admin account is protected — it can never be deleted.
        $notSuperAdmin = fn (Employee $record) => ! $record->hasRole('super_admin');

        return [
            DeleteAction::make()->visible($notSuperAdmin),
            ForceDeleteAction::make()->visible($notSuperAdmin),
            RestoreAction::make(),
        ];
    }
}
