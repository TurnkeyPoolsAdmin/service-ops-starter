<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-install ADAS calibration partner pipeline (the calibration partner).
 * needs_calibration already exists (2026-07-14); this adds the status machine
 * + schedule/complete/confirm stamps + PDF paths.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // needed | scheduled | completed — null until install is marked complete
            // on a job that needsCalibration().
            $table->string('calibration_status', 32)->nullable()->after('needs_calibration');
            $table->timestamp('calibration_scheduled_at')->nullable()->after('calibration_status');
            $table->foreignId('calibration_scheduled_by')->nullable()->after('calibration_scheduled_at')
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('calibration_completed_at')->nullable()->after('calibration_scheduled_by');
            $table->foreignId('calibration_completed_by')->nullable()->after('calibration_completed_at')
                ->constrained('employees')->nullOnDelete();
            $table->string('calibration_invoice_path')->nullable()->after('calibration_completed_by');
            $table->string('calibration_report_path')->nullable()->after('calibration_invoice_path');
            $table->timestamp('calibration_confirmed_at')->nullable()->after('calibration_report_path');
            $table->foreignId('calibration_confirmed_by')->nullable()->after('calibration_confirmed_at')
                ->constrained('employees')->nullOnDelete();
            $table->boolean('calibration_went_well')->nullable()->after('calibration_confirmed_by');

            $table->index('calibration_status');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('calibration_scheduled_by');
            $table->dropConstrainedForeignId('calibration_completed_by');
            $table->dropConstrainedForeignId('calibration_confirmed_by');
            $table->dropIndex(['calibration_status']);
            $table->dropColumn([
                'calibration_status',
                'calibration_scheduled_at',
                'calibration_completed_at',
                'calibration_invoice_path',
                'calibration_report_path',
                'calibration_confirmed_at',
                'calibration_went_well',
            ]);
        });
    }
};
