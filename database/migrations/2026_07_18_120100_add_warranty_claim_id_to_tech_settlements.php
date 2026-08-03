<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A warranty return visit's extra tech pay settles through the same
 * TechSettlement machinery as an End-of-Day payout, as a new `type` =
 * 'warranty_payout' row. Link it back to the claim it paid so the End-of-Day
 * warranty widget and the audit trail can tie the money to the visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_settlements', function (Blueprint $table) {
            $table->foreignId('warranty_claim_id')->nullable()->after('technician_id')
                ->constrained('warranty_claims')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tech_settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warranty_claim_id');
        });
    }
};
