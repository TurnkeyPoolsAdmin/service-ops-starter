<?php

namespace App\Console\Commands;

use App\Services\Reporting\CancellationsExport;
use App\Services\Reporting\TechLaborExport;
use App\Support\Timezone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Writes the two owner-facing data exports to disk, for when the files are
 * wanted over SSH rather than through the Data Exports page. Read-only — it
 * builds a report and never touches a job.
 *
 *   php artisan reports:export cancellations
 *   php artisan reports:export tech-labor
 *   php artisan reports:export all
 */
class ExportDataSet extends Command
{
    protected $signature = 'reports:export
        {dataset=all : cancellations | tech-labor | all}
        {--path= : Directory to write into (defaults to storage/app/exports)}';

    protected $description = 'Export the cancellations and tech-labor data sets as CSV.';

    public function handle(): int
    {
        $dataset = (string) $this->argument('dataset');

        $exports = [
            CancellationsExport::FILENAME => fn () => (new CancellationsExport)->toCsv(),
            TechLaborExport::FILENAME => fn () => (new TechLaborExport)->toCsv(),
        ];

        if ($dataset !== 'all' && ! isset($exports[$dataset])) {
            $this->error("Unknown data set [{$dataset}]. Use: cancellations, tech-labor, or all.");

            return self::FAILURE;
        }

        if ($dataset !== 'all') {
            $exports = [$dataset => $exports[$dataset]];
        }

        $stamp = Timezone::now()->format('Y-m-d');

        foreach ($exports as $name => $build) {
            $file = "{$name}-{$stamp}.csv";
            $csv = $build();

            if ($path = $this->option('path')) {
                $target = rtrim((string) $path, '/')."/{$file}";
                file_put_contents($target, $csv);
            } else {
                $target = Storage::disk('local')->path("exports/{$file}");
                Storage::disk('local')->put("exports/{$file}", $csv);
            }

            // Subtract the header line so the count is rows of data.
            $this->info("Wrote {$target} (".max(0, substr_count($csv, "\n") - 1).' rows)');
        }

        return self::SUCCESS;
    }
}
