<?php

use App\Enums\TimePreference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structure the customer's requested TIME so the post-welcome scheduling ask
 * can branch on it (App\Support\SchedulingAsk): none / asap / morning /
 * afternoon / evening / specific. The existing free-text `requested_time` stays
 * put and keeps holding the specific clock time.
 *
 * Existing rows are backfilled by reading their free text through
 * TimePreference::normalize(); anything it can't classify is left null, which
 * Job::timePreference() reads as "no preference".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->string('requested_time_preference')->nullable()->after('requested_time');
        });

        DB::table('jobs')
            ->whereNotNull('requested_time')
            ->select('id', 'requested_time')
            ->orderBy('id')
            ->chunk(500, function ($jobs) {
                foreach ($jobs as $job) {
                    $preference = TimePreference::normalize($job->requested_time);
                    if ($preference !== null) {
                        DB::table('jobs')->where('id', $job->id)
                            ->update(['requested_time_preference' => $preference->value]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('requested_time_preference');
        });
    }
};
