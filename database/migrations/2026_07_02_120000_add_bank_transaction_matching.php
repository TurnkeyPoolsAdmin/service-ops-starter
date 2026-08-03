<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Weekly bank reconciliation: every Chase transaction gets "accounted for" by
     * linking it to what it actually was — a customer payment (job), a parts
     * purchase (job), a tech payout (End-of-Day settlement), or a misc category
     * (Google Ads, card autopay, fees...). Cash never appears here by definition.
     *
     * A transaction's lifecycle: unmatched → suggested (auto-matcher proposed a
     * link, human hasn't confirmed) → matched (human confirmed, or a deterministic
     * auto rule like Google Ads). The links themselves live in
     * bank_transaction_matches — one row per linked record, so a single vendor
     * charge covering parts for two jobs is two rows.
     */
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->string('match_status')->default('unmatched')->after('pending'); // unmatched | suggested | matched
            $table->timestamp('matched_at')->nullable()->after('match_status');
            $table->foreignId('matched_by')->nullable()->after('matched_at')->constrained('employees')->nullOnDelete();

            $table->index('match_status');
        });

        Schema::create('bank_transaction_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_transaction_id')->constrained('bank_transactions')->cascadeOnDelete();

            $table->string('kind'); // job_payment | part_purchase | tech_payout | misc

            // Exactly one of these is set for job/tech kinds; both null for misc.
            $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->foreignId('tech_settlement_id')->nullable()->constrained('tech_settlements')->nullOnDelete();

            $table->string('misc_category')->nullable(); // google_ads | cc_payment | bank_fee | transfer | refund | other
            $table->string('source')->default('manual'); // auto (matcher) | manual (human)
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index('job_id');
            $table->index('tech_settlement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transaction_matches');

        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('matched_by');
            $table->dropColumn(['match_status', 'matched_at']);
        });
    }
};
