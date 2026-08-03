<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a bank_transaction_matches row (kind = vendor_invoice) point at the
 * vendor_documents row it reconciles — one Mygrant card charge can settle
 * SEVERAL documents at once (an invoice plus a same-cycle credit memo, or two
 * invoices when a prior charge was skipped), so one match row per document,
 * same shape as a part_purchase charge spanning several jobs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transaction_matches', function (Blueprint $table) {
            $table->foreignId('vendor_document_id')->nullable()->after('tech_settlement_id')
                ->constrained('vendor_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_transaction_matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_document_id');
        });
    }
};
