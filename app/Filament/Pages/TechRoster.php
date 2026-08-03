<?php

namespace App\Filament\Pages;

use App\Enums\JobFunction;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Models\Entity;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Roster of technicians grouped by tech pool (Entity::techPoolEntityIds — LA +
 * SFV share one crew/one ranking, Del Mar ranks its own), showing each tech's
 * assignment priority and flat per-job rate. One tab per pool.
 *
 * The ranking is drag-to-reorder here (see table()); everything else on the
 * page is read-only and edits go through the Employee resource.
 */
class TechRoster extends Page implements HasTable
{
    use GatedByPermission;
    use InteractsWithTable;

    protected static function requiredPermission(): string
    {
        return 'manage_employees';
    }

    // Reached via the Admin > Settings hub, not its own sidebar item.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected string $view = 'filament.pages.tech-roster';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $title = 'Tech Roster';

    protected static ?string $navigationLabel = 'Tech Roster';

    public function getSubheading(): ?string
    {
        return 'Drag a row to set the ranking for this pool. It orders the tech picker and is the default order the AI scheduler texts technicians in.';
    }

    /** The selected pool's key (see poolGroups()). */
    #[Url]
    public ?string $pool = null;

    public function mount(): void
    {
        $groups = static::poolGroups();

        if ($this->pool === null || ! collect($groups)->contains('key', $this->pool)) {
            $this->pool = $groups[0]['key'] ?? null;
        }
    }

    /** One tab button per tech pool. */
    protected function getHeaderActions(): array
    {
        return collect(static::poolGroups())
            ->map(fn (array $group) => Action::make('pool_'.$group['key'])
                ->label($group['label'])
                ->color(fn () => $this->pool === $group['key'] ? 'primary' : 'gray')
                ->action(function () use ($group) {
                    $this->pool = $group['key'];
                    $this->resetTable();
                }))
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->tableQuery())
            ->paginated(false)
            // Drag-and-drop IS how a rank gets set now — the alternative was
            // opening each tech and typing a number, which is why rankings
            // went stale. Dropping a row rewrites `priority` to 1..N for the
            // whole pool shown, so previously unranked techs get a number too.
            // This is the DEFAULT order the AI scheduler contacts techs in;
            // a single job can still override it on the "Schedule w/ AI" modal.
            ->reorderable('priority')
            ->columns([
                TextColumn::make('priority')
                    ->label('Rank')
                    ->state(fn (Employee $record) => $record->priorityLabel() ?? '—')
                    ->badge()
                    ->color(fn (Employee $record) => $record->priority === null ? 'gray' : 'primary'),
                TextColumn::make('name')->label('Technician')->weight('bold')->searchable(),
                TextColumn::make('entity.code')->label('Loc')->badge()->placeholder('—'),
                TextColumn::make('fixed_cost_cents')
                    ->label('Fixed cost per job')
                    ->state(fn (Employee $record) => $record->fixed_cost_cents !== null
                        ? Money::format($record->fixed_cost_cents)
                        : '—')
                    ->alignEnd(),
                TextColumn::make('phone')->label('Phone')->placeholder('—')
                    ->visibleFrom('md'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencil)
                    ->color('gray')
                    ->url(fn (Employee $record) => EmployeeResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading('No technicians in this pool')
            ->emptyStateDescription('Add a technician under Employees, or assign one to this location.')
            ->emptyStateIcon(Heroicon::OutlinedUserGroup);
    }

    private function tableQuery(): Builder
    {
        $group = collect(static::poolGroups())->firstWhere('key', $this->pool);
        $entityIds = $group['entity_ids'] ?? [];

        return Employee::query()
            ->active()
            ->withJobFunction(JobFunction::Technician)
            ->whereIn('entity_id', $entityIds)
            ->orderByPriority();
    }

    /**
     * One group per distinct tech pool — entities sharing a pool
     * (Entity::techPoolEntityIds) collapse into a single tab, keyed by the
     * first entity's id in the group and labeled via techPoolLabel().
     */
    private static function poolGroups(): array
    {
        $entities = Entity::query()->where('active', true)->orderBy('name')->get();
        $seenIds = [];
        $groups = [];

        foreach ($entities as $entity) {
            if (in_array($entity->id, $seenIds, true)) {
                continue;
            }

            $entityIds = $entity->techPoolEntityIds();
            $seenIds = array_merge($seenIds, $entityIds);

            $groups[] = [
                'key' => (string) $entity->id,
                'label' => $entity->techPoolLabel(),
                'entity_ids' => $entityIds,
            ];
        }

        return $groups;
    }
}
