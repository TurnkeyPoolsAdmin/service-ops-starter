<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema for the call-first review rework (2026-07-23, see ReviewDripService).
 * Entry moved from "how did the job go?" at payment/completion to a new
 * `awaiting_call` status opened the instant work is marked complete (or, for a
 * calibration job, the instant ops confirms calibration). Dispatch is prompted
 * to call the customer immediately; the sentiment question moved onto that
 * call log instead of the old thumbs-up/down dialogs.
 *
 * `sentiment` is now nullable — an awaiting_call row hasn't been asked yet.
 * `step1_template` mirrors the existing `step3_template` column: it records
 * which text 1 actually went out (review_connected after a connected first
 * call, review_request_1 otherwise), so a blocked send retries with the same
 * template rather than re-deciding from a first_call_outcome that may have
 * since changed shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_requests', function (Blueprint $table) {
            $table->string('sentiment', 10)->nullable()->change();

            // The first call — logged from the Reviews dashboard right after
            // completion, or auto-filled by the 7pm deadline sweep.
            $table->string('first_call_outcome', 20)->nullable()->after('sentiment'); // connected | no_answer | auto
            $table->timestamp('first_call_logged_at')->nullable()->after('first_call_outcome');
            $table->foreignId('first_call_logged_by')->nullable()->after('first_call_logged_at')->constrained('employees');
            $table->timestamp('first_call_skipped_at')->nullable()->after('first_call_logged_by');
            $table->string('step1_template', 40)->nullable()->after('first_call_skipped_at'); // which text 1 went out
        });
    }

    public function down(): void
    {
        Schema::table('review_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('first_call_logged_by');
            $table->dropColumn([
                'first_call_outcome', 'first_call_logged_at', 'first_call_skipped_at', 'step1_template',
            ]);
            $table->string('sentiment', 10)->nullable(false)->change();
        });
    }
};
