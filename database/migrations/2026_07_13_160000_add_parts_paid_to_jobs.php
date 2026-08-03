<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stamps when a job's part was PAID FOR (JobLifecycle::markPartsPaid). Only
 * matters for "Other"-warehouse jobs — the dealers/one-off suppliers we don't
 * have an account with, so the part must be prepaid before it's released.
 * Drives the "Prepay pending" tab + the prepay Telegram alerts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->timestamp('parts_paid_at')->nullable()->after('parts_ordered_by');
            $table->foreignId('parts_paid_by')->nullable()->after('parts_paid_at')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parts_paid_by');
            $table->dropColumn('parts_paid_at');
        });
    }
};
