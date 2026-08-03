<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatedByPermission;
use App\Services\Reporting\CancellationsExport;
use App\Services\Reporting\TechLaborExport;
use App\Support\Timezone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Admin > Data Exports. Downloads the two owner-facing data sets as CSV so they
 * can be opened in Excel and pivoted offline.
 *
 * Read-only: each button builds a report from live data and streams it. Nothing
 * here writes to a job. The same two data sets are available over SSH via
 * `php artisan reports:export`.
 */
class DataExports extends Page
{
    use GatedByPermission;

    // Admin-only — these sheets carry customer phone numbers, money and pay.
    protected static function requiredPermission(): string
    {
        return 'access_admin';
    }

    protected string $view = 'filament.pages.data-exports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $title = 'Data Exports';

    protected static ?string $navigationLabel = 'Data Exports';

    protected static ?int $navigationSort = 27;

    public function getSubheading(): ?string
    {
        return 'Download the raw data sets as CSV. Both open directly in Excel; money columns are '
            .'plain numbers so you can sum and pivot them.';
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadCancellations')
                ->label('Download cancellations')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn () => $this->download(
                    CancellationsExport::FILENAME,
                    (new CancellationsExport)->toCsv(),
                )),

            Action::make('downloadTechLabor')
                ->label('Download tech labor')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn () => $this->download(
                    TechLaborExport::FILENAME,
                    (new TechLaborExport)->toCsv(),
                )),
        ];
    }

    private function download(string $name, string $csv): StreamedResponse
    {
        $file = $name.'-'.Timezone::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(
            fn () => print ($csv),
            $file,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * Row counts + column lists for the page body, so the owner can see what is
     * in each sheet before downloading it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDataSets(): array
    {
        $cancellations = new CancellationsExport;
        $techLabor = new TechLaborExport;

        return [
            [
                'key' => 'cancellations',
                'title' => 'Cancellations',
                'description' => 'Every canceled job and lost lead, with the reason, the money involved, '
                    .'a link to the job and a link to the Quo conversation. Split the Status column to '
                    .'separate canceled jobs from leads that never converted.',
                'columns' => $cancellations->header(),
                'rows' => count($cancellations->rows()),
            ],
            [
                'key' => 'tech-labor',
                'title' => 'Tech labor',
                'description' => 'Labor per technician per job: job number, car type, technician and amount. '
                    .'The Type column separates normal jobs from paid warranty visits, and Pay status '
                    .'separates what is settled from what is still owed.',
                'columns' => $techLabor->header(),
                'rows' => count($techLabor->rows()),
            ],
        ];
    }
}
