<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-day installs: some jobs take two visits (the install is started one day
 * and finished the next). The job stays ONE job — the first visit's completion
 * just sends it back around the scheduling loop instead of finishing it.
 *
 * `multi_day_first_completed_at` is the stage marker: null = the next completion
 * is the FIRST day (and must be followed by scheduling the second), set = the
 * next completion is the real one and runs the normal payment/review flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->boolean('is_multi_day')->default(false)->after('needs_calibration');
            $table->timestamp('multi_day_first_completed_at')->nullable()->after('is_multi_day');
            $table->foreignId('multi_day_first_completed_by')->nullable()
                ->after('multi_day_first_completed_at')->constrained('employees');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('multi_day_first_completed_by');
            $table->dropColumn(['is_multi_day', 'multi_day_first_completed_at']);
        });
    }
};
