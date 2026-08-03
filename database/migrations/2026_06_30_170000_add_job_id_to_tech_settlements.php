<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash a tech collects now lands on their balance the moment the payment is
     * recorded (not at End-of-Day settle time), and each such move writes a
     * `cash_collected` audit row. Those rows point at the job the cash came from,
     * so a tech's balance can be explained line-by-line ("+$200 from LA-2026-0018").
     * Nullable because eod_payout / balance_clear rows span many jobs (or none).
     */
    public function up(): void
    {
        Schema::table('tech_settlements', function (Blueprint $table) {
            $table->foreignId('job_id')->nullable()->after('for_date')->constrained('jobs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tech_settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_id');
        });
    }
};
