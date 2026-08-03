<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Approve each" mode (docs/ai-scheduling-spec.md §0.6) needs somewhere to
 * park an outbound text that has been composed but not sent. Rather than a
 * second table, the outbound TURN is the pending record: it already carries
 * the rendered body, the recipient and the attempt, so the transcript and the
 * approval queue are the same rows.
 *
 * approval_status: null   = nothing to approve (simulate/live, or an inbound)
 *                  pending  = composed, waiting on a dispatcher click
 *                  approved = the dispatcher sent it
 *                  skipped  = the dispatcher declined to send it
 * pending_payload: what sendOutbound() needs to actually queue it later —
 *                  the template key and the extra merge vars, which are
 *                  computed at compose time and can't be re-derived once the
 *                  session has moved on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_scheduling_turns', function (Blueprint $table) {
            $table->string('approval_status')->nullable()->after('used_llm');
            $table->json('pending_payload')->nullable()->after('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_scheduling_turns', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'pending_payload']);
        });
    }
};
