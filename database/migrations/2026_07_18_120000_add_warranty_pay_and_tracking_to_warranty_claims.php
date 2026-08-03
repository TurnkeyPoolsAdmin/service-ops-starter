<?php

use App\Models\WarrantyClaim;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Warranty flow expansion (2026-07-18). A warranty return visit can now carry
 * OPTIONAL extra tech pay (entered when the visit is scheduled), which flows
 * through the End-of-Day summary + bank reconciliation exactly like a normal
 * payout; the claim tracks who ORIGINALLY did the job (so sending a different
 * tech to fix it raises an Issue); a "how did it go" sentiment recorded at
 * completion; and the overdue-completion Telegram stamp (mirrors jobs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table) {
            // The tech who did the ORIGINAL job — captured when the claim opens.
            // Scheduling the visit with a different tech raises an Issue.
            $table->foreignId('original_technician_id')->nullable()->after('technician_id')->constrained('employees');

            // Optional extra pay for the return visit (cents). Null / 0 = unpaid,
            // the default. When set, it becomes owed on completion and is settled
            // + bank-matched via a dedicated warranty_payout TechSettlement.
            $table->unsignedInteger('tech_pay_cents')->nullable()->after('scheduled_by');
            $table->timestamp('tech_paid_at')->nullable()->after('tech_pay_cents');
            $table->foreignId('tech_paid_by')->nullable()->after('tech_paid_at')->constrained('employees');
            $table->string('tech_paid_method', 40)->nullable()->after('tech_paid_by');
            $table->string('tech_paid_reference', 255)->nullable()->after('tech_paid_method');

            // "How did it go?" at completion — informational only (NO review drip).
            $table->string('sentiment', 10)->nullable()->after('resolution_notes');

            // Overdue-completion Telegram stamp: set on a successful alert, nulled
            // on any (re)schedule so a pushed-back visit can re-arm (mirrors jobs).
            $table->timestamp('overdue_completion_alerted_at')->nullable()->after('sentiment');
        });

        // Backfill: existing claims' original tech = whoever is currently on them.
        DB::table('warranty_claims')
            ->whereNull('original_technician_id')
            ->update(['original_technician_id' => DB::raw('technician_id')]);
    }

    public function down(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table) {
            $table->dropConstrainedForeignId('original_technician_id');
            $table->dropConstrainedForeignId('tech_paid_by');
            $table->dropColumn([
                'tech_pay_cents', 'tech_paid_at', 'tech_paid_method',
                'tech_paid_reference', 'sentiment', 'overdue_completion_alerted_at',
            ]);
        });
    }
};
