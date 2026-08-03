<?php

namespace App\Filament\Resources\Jobs\Pages;

use App\Filament\Resources\Jobs\JobResource;
use App\Filament\Resources\Jobs\Support\JobActions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;

class ViewJob extends ViewRecord
{
    protected static string $resource = JobResource::class;

    // No top-level Edit button: each view section has its own inline "Edit"
    // action (see JobInfolist) so a single section can be edited without
    // reloading the whole record.
    protected function getHeaderActions(): array
    {
        return [
            JobActions::call(),
            JobActions::messaging(),
            ActionGroup::make(JobActions::all())->label('Actions')->button(),
        ];
    }

    // Mounted by id from the notes feed (`mountAction('editJobNote', { note: N })`),
    // so they need no button of their own. Author-only — enforced in the action.
    public function editJobNoteAction(): Action
    {
        return JobActions::editNote();
    }

    public function deleteJobNoteAction(): Action
    {
        return JobActions::deleteNote();
    }
}
