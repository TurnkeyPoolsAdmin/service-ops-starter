<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "On hold" for a job that still needs scheduling. The lead is real and the work
 * is still ours to book — we are just waiting to hear back from the customer
 * (they have to check a schedule, confirm the quote, call their insurer), so the
 * job should stop reading as a queue dispatch can act on today.
 *
 * It is deliberately NOT a JobStatus: the job stays `pending_schedule` so every
 * other path (texts, intake, scheduling, the AI negotiation) is untouched. The
 * hold is a stamp Job::scopeNeedsScheduling excludes, which is what takes the
 * job off the board tab, Open Tasks and the ops digests all at once.
 *
 * See JobLifecycle::placeOnSchedulingHold / releaseSchedulingHold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->timestamp('scheduling_hold_at')->nullable()->after('requested_after_time');
            $table->string('scheduling_hold_reason')->nullable()->after('scheduling_hold_at');
            $table->foreignId('scheduling_hold_by')->nullable()->after('scheduling_hold_reason')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scheduling_hold_by');
            $table->dropColumn(['scheduling_hold_at', 'scheduling_hold_reason']);
        });
    }
};
