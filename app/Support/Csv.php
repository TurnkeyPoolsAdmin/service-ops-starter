<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Minimal CSV writer for the data exports.
 *
 * We have no spreadsheet library (no phpoffice/openspout — see composer.json)
 * and do not want one for two report downloads, so exports ship as CSV. Excel
 * opens CSV natively; the UTF-8 BOM is what makes it read accented names and
 * Spanish copy correctly instead of mojibake.
 *
 * Money columns are written as PLAIN NUMBERS ("250.00", not "$250.00") so the
 * owner can sum and pivot them in Excel without stripping currency symbols.
 */
class Csv
{
    /** Byte-order mark. Excel needs it to detect UTF-8 in a .csv. */
    public const BOM = "\xEF\xBB\xBF";

    /**
     * Build a full CSV document from a header row and data rows.
     *
     * @param  array<int, string>  $header
     * @param  array<int, array<int, mixed>>  $rows
     */
    public static function build(array $header, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fwrite($handle, self::BOM);
        fputcsv($handle, $header);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::cell(...), $row));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Normalize one value for a cell. Nulls become empty (not the literal
     * "null"), booleans become Yes/No so a human reading the sheet does not
     * have to decode 1/0, and everything else is stringified as-is.
     */
    private static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return (string) $value;
    }

    /** Integer cents as a bare decimal for Excel math. Null stays empty. */
    public static function money(?int $cents): ?string
    {
        return $cents === null ? null : number_format($cents / 100, 2, '.', '');
    }

    /** A stored UTC timestamp rendered in Pacific for the operator. */
    public static function dateTime(mixed $utc, string $format = 'Y-m-d H:i'): ?string
    {
        if (! $utc instanceof CarbonInterface) {
            return null;
        }

        return Timezone::toDisplay($utc)?->format($format);
    }

    /** Just the Pacific calendar date — the useful grain for pivots. */
    public static function date(mixed $utc): ?string
    {
        return self::dateTime($utc, 'Y-m-d');
    }
}
