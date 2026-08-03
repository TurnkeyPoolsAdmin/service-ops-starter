<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets an eod_payout settlement be undone: settled_job_ids pins down exactly
     * which jobs it stamped tech-paid (so undo can unstamp precisely those and no
     * others, even if later settlements touch the same tech), undone_at/by is the
     * audit stamp for the reversal itself. Settlements are never deleted — an
     * undone row stays in the history with undone_at set.
     */
    public function up(): void
    {
        Schema::table('tech_settlements', function (Blueprint $table) {
            $table->json('settled_job_ids')->nullable()->after('job_count');
            $table->timestamp('undone_at')->nullable()->after('recorded_by');
            $table->foreignId('undone_by')->nullable()->after('undone_at')->constrained('employees');
        });
    }

    public function down(): void
    {
        Schema::table('tech_settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('undone_by');
            $table->dropColumn(['settled_job_ids', 'undone_at']);
        });
    }
};
