<?php

use App\Enums\TimePreference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The hour behind the new "After a time" requested-time preference
 * (TimePreference::After) — a 'H:i' wall-clock time, Pacific, meaning "any
 * time from here to close".
 *
 * It gets its own column rather than riding the free-text `requested_time`
 * because the AI scheduler turns it straight into the earliest bound of the
 * negotiation window; a value it could not parse would silently degrade to
 * "no preference" and the customer would be offered 8am after asking for
 * after 3pm.
 *
 * Existing rows whose free text already says "after 10am" are read onto the
 * new case + column — but ONLY where nobody classified them by hand: a null
 * preference, or the "specific" the 2026_07_20 backfill gave anything with a
 * digit in it. A dispatcher's own Morning/Afternoon choice is never rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->string('requested_after_time', 5)->nullable()->after('requested_time_preference');
        });

        DB::table('jobs')
            ->whereNotNull('requested_time')
            ->where(function ($query) {
                $query->whereNull('requested_time_preference')
                    ->orWhere('requested_time_preference', TimePreference::Specific->value);
            })
            ->select('id', 'requested_time', 'requested_time_preference')
            ->orderBy('id')
            ->chunk(500, function ($jobs) {
                foreach ($jobs as $job) {
                    if (TimePreference::normalize($job->requested_time) !== TimePreference::After) {
                        continue;
                    }

                    $after = TimePreference::afterTimeFrom($job->requested_time);
                    if ($after === null) {
                        continue;
                    }

                    DB::table('jobs')->where('id', $job->id)->update([
                        'requested_time_preference' => TimePreference::After->value,
                        'requested_after_time' => $after,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('requested_after_time');
        });
    }
};
