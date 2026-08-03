<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\IsTileHub;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Entities\EntityResource;
use App\Filament\Resources\Holidays\HolidayResource;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Services\TestJobFactory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Admin > Settings hub. A single landing page that shows a tile per admin
 * config area (Employees, Entities, Templates, Warehouses, Integrations,
 * Reminder Timing, Holidays). Each tile is rendered only if the logged-in
 * user can access that area, so the grid respects the same per-permission
 * gating the individual resources/pages already declare. The underlying
 * resources/pages no longer register their own sidebar item — they live here.
 */
class Settings extends Page
{
    use IsTileHub;

    protected string $view = 'filament.pages.tile-hub';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    protected static ?int $navigationSort = 0;

    /**
     * The tile definitions for this hub. Order matches the sidebar order the
     * individual items used to have.
     */
    protected static function tileClasses(): array
    {
        return [
            ['class' => EmployeeResource::class, 'label' => 'Employees', 'icon' => Heroicon::OutlinedUsers],
            ['class' => TechRoster::class, 'label' => 'Tech Roster', 'icon' => Heroicon::OutlinedUserGroup],
            ['class' => EntityResource::class, 'label' => 'Entities', 'icon' => Heroicon::OutlinedRectangleStack],
            ['class' => MessageTemplateResource::class, 'label' => 'Message Templates', 'icon' => Heroicon::OutlinedChatBubbleLeftRight],
            ['class' => WarehouseResource::class, 'label' => 'Warehouses', 'icon' => Heroicon::OutlinedBuildingStorefront],
            ['class' => ManageIntegrations::class, 'label' => 'Integrations', 'icon' => Heroicon::OutlinedKey],
            ['class' => ManageTiming::class, 'label' => 'Reminder Timing', 'icon' => Heroicon::OutlinedClock],
            ['class' => HolidayResource::class, 'label' => 'Holidays', 'icon' => Heroicon::OutlinedCalendarDays],
        ];
    }

    /**
     * One-click dummy job for testing a new feature end-to-end. Placeholder
     * everything except the phone, which is always the tester's real line
     * BECAUSE the point is that texts do send - the job runs the ordinary
     * lifecycle, welcome text and all. Deleting it purges it, so a test job
     * never lands in the Issues queue.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('loadTestJob')
                ->label('Load a test job')
                ->icon(Heroicon::OutlinedBeaker)
                ->color('gray')
                ->visible(fn () => auth()->user()?->can('manage_settings') ?? false)
                ->requiresConfirmation()
                ->modalHeading('Load a test job?')
                ->modalDescription('Creates a dummy pending-schedule job with placeholder details and the test phone '
                    .TestJobFactory::PHONE.'. It behaves like any other job - every text really sends, to that number -'
                    .' so you can test a feature end to end. Delete it when you are done and it will not raise an Issue.')
                ->modalSubmitActionLabel('Create it')
                ->action(function () {
                    $job = app(TestJobFactory::class)->create(auth()->user());

                    Notification::make()->title("Test job {$job->job_number} created")->success()->send();

                    // Same next step as a real lead: pick the welcome to send.
                    $this->redirect(SendWelcome::getUrl(['job' => $job->id]));
                }),
        ];
    }
}
