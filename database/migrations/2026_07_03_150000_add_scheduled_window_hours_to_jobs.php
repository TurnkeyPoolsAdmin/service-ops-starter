<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // Per-job override of the arrival-window length ("different time
            // window" in the Schedule dialog: 3h or 4h). NULL = the default from
            // config glass.appointment_window_hours (2h). Ignored when the job is
            // flagged exact-time. Only affects customer-facing phrasing.
            $table->unsignedTinyInteger('scheduled_window_hours')->nullable()->after('scheduled_exact_time');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('scheduled_window_hours');
        });
    }
};
