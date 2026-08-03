<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a job_payment-kind BankTransactionMatch point at a LegacyManualPayment
 * row instead of a real Job/JobPayment, for the pre-Job-tracking era (Sept
 * 2025 - Apr 2026). job_id/job_payment_id stay null on these matches — same
 * "exactly one of the possible references is set" shape the kind already
 * has for job vs tech_settlement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transaction_matches', function (Blueprint $table) {
            $table->foreignId('legacy_manual_payment_id')->nullable()->after('job_payment_id')
                ->constrained('legacy_manual_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transaction_matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legacy_manual_payment_id');
        });
    }
};
