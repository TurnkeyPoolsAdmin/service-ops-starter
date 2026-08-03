<?php

namespace App\Support;

use App\Models\Job;
use Illuminate\Database\Eloquent\Builder;

/**
 * ONE search rule for jobs, shared by the topbar global search
 * (JobResource::applyGlobalSearchAttributeConstraints) and the jobs board's
 * own search box (JobsTable's `searchUsing`). Keep them on this helper so the
 * two boxes can never disagree about what a search term means.
 *
 * A plain LIKE on one column fails on the two things an operator actually
 * types, which is why this exists:
 *  - **"Smith John" / "Smith, John"** - the snapshot reads "John Smith", so
 *    each word is matched INDEPENDENTLY. Every word must hit SOME field
 *    (AND across words, OR across fields), but not the same field and not in
 *    the typed order. This also makes "Smith Camry" and "John 90210" work.
 *  - **"(310) 555-1234"** - phones are stored E.164 (`+13105551234`), so a
 *    typed number is reduced to its digits before it is compared. Never LIKE
 *    the raw word against a phone column; separators are what break it.
 *
 * Search reads BOTH the job's snapshot columns and the `customers` row. The
 * snapshot is written once at intake, so a later correction to the customer's
 * name or phone only lives on `customers` - a search on the corrected value
 * would otherwise find nothing.
 */
class JobSearch
{
    /**
     * Below this, digits are a house number or a year, not a phone fragment -
     * matching them against phones makes every result noise.
     */
    private const MIN_PHONE_DIGITS = 3;

    /**
     * Columns on `jobs` a single word may match. Order is display order only.
     *
     * @var array<int, string>
     */
    public const JOB_COLUMNS = [
        'customer_name_snapshot',
        'job_number',
        'service_address',
        'city',
        'part_number',
        'vin',
        'vehicle_year',
        'vehicle_make',
        'vehicle_model',
        'vehicle_raw',
        'warehouse_other',
    ];

    /**
     * A phone typed with spaces ("+1 310 555 1234", "310 555 1234") would
     * otherwise be split into words, and "+1" matches nothing on its own -
     * one dead word fails the whole AND. So a term that is ONLY phone
     * characters is treated as a single number instead of as words.
     */
    private const PHONE_TERM = '/^[\d\s()+.\-]+$/';

    private const MIN_WHOLE_PHONE_DIGITS = 7;

    /**
     * @param  Builder<Job>  $query
     * @return Builder<Job>
     */
    public static function apply(Builder $query, string $search): Builder
    {
        if ($digits = self::phoneTermDigits($search)) {
            return $query->where(fn (Builder $query) => self::matchPhone($query, $digits));
        }

        foreach (self::words($search) as $word) {
            $query->where(fn (Builder $query) => self::matchWord($query, $word));
        }

        return $query;
    }

    /** The search term read as one whole phone number, or null if it isn't one. */
    private static function phoneTermDigits(string $search): ?string
    {
        $search = trim($search);

        if ($search === '' || ! preg_match(self::PHONE_TERM, $search)) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', $search);

        return strlen($digits) >= self::MIN_WHOLE_PHONE_DIGITS ? $digits : null;
    }

    /**
     * @param  Builder<Job>  $query
     */
    private static function matchPhone(Builder $query, string $digits): void
    {
        $query->where('customer_phone_snapshot', 'like', '%'.$digits.'%')
            // An all-digit part number is the one other thing that reads like a
            // phone; let it match rather than silently returning nothing.
            ->orWhere('part_number', 'like', '%'.$digits.'%')
            ->orWhereHas('customer', fn (Builder $query) => $query->where('phone_e164', 'like', '%'.$digits.'%'));
    }

    /**
     * Split on whitespace AND commas, so "Smith, John" is two words.
     *
     * @return array<int, string>
     */
    public static function words(string $search): array
    {
        $words = preg_split('/[\s,]+/u', trim($search)) ?: [];

        return array_values(array_filter($words, fn (string $word): bool => $word !== ''));
    }

    /**
     * @param  Builder<Job>  $query
     */
    private static function matchWord(Builder $query, string $word): void
    {
        $like = '%'.$word.'%';
        $digits = (string) preg_replace('/\D+/', '', $word);
        $isPhone = strlen($digits) >= self::MIN_PHONE_DIGITS;

        $isFirst = true;

        foreach (self::JOB_COLUMNS as $column) {
            $query->{$isFirst ? 'where' : 'orWhere'}($column, 'like', $like);
            $isFirst = false;
        }

        if ($isPhone) {
            $query->orWhere('customer_phone_snapshot', 'like', '%'.$digits.'%');
        }

        $query->orWhereHas('customer', function (Builder $query) use ($like, $digits, $isPhone): void {
            $query->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like);

            if ($isPhone) {
                $query->orWhere('phone_e164', 'like', '%'.$digits.'%');
            }
        });
    }
}
