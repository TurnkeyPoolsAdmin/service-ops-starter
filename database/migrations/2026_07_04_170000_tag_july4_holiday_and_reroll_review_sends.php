<?php

use App\Models\Holiday;
use App\Models\ReviewRequest;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;

/**
 * Two data fixes that pair with the "skip Sundays and holidays" review-drip
 * change:
 *
 *  1. Tag July 4th (Independence Day) as an annual holiday. Holidays are purely
 *     admin-entered — there's no built-in US-holiday calendar — so the shop's
 *     closed days must be listed for the dispatch-intro + review-cadence logic
 *     to recognize them.
 *
 *  2. Re-roll any ALREADY-scheduled review-drip send that now lands on a closed
 *     day. The skip logic only affects send times computed after it shipped; a
 *     drip whose next step was scheduled earlier keeps its old time. This walks
 *     every active request with a pending send and, if it falls on a Sunday or
 *     a tagged holiday (incl. the July 4th rule added just above), pushes it to
 *     the next open day at the same wall-clock time.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Independence Day — same month/day every year.
        $exists = Holiday::query()
            ->where('type', Holiday::TYPE_ANNUAL)
            ->where('month', 7)->where('day', 4)
            ->exists();

        if (! $exists) {
            Holiday::create([
                'type' => Holiday::TYPE_ANNUAL,
                'month' => 7,
                'day' => 4,
                'label' => 'Independence Day',
            ]);
        }

        // 2. Re-roll pending review sends off closed days. Only ACTIVE requests
        //    with a still-pending next_send_at qualify (a sent text 2 clears
        //    next_send_at, so already-sent steps are naturally excluded).
        $tz = config('glass.timezone', 'America/Los_Angeles');

        ReviewRequest::query()
            ->where('status', ReviewRequest::STATUS_ACTIVE)
            ->whereNotNull('next_send_at')
            ->get()
            ->each(function (ReviewRequest $request) use ($tz) {
                $local = $request->next_send_at->copy()->setTimezone($tz);
                $rolled = $local->copy();

                while ($rolled->isSunday() || Holiday::isHoliday($rolled)) {
                    $rolled = $rolled->copy()->addDay();
                }

                if (! $rolled->equalTo($local)) {
                    $request->forceFill([
                        'next_send_at' => $rolled->copy()->setTimezone('UTC'),
                    ])->save();
                }
            });
    }

    public function down(): void
    {
        // Only the holiday tag is reversible; the re-rolled send times are left
        // as-is (rolling them back onto a closed day would be wrong).
        Holiday::query()
            ->where('type', Holiday::TYPE_ANNUAL)
            ->where('month', 7)->where('day', 4)
            ->where('label', 'Independence Day')
            ->delete();
    }
};
