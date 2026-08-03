<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // ADAS calibration needed for this windshield. Optional at intake
            // (most jobs don't need it); insurance jobs always do (enforced in
            // code via Job::needsCalibration(), not stored), so the stored flag
            // is purely the manual "this one needs it" mark for non-insurance jobs.
            $table->boolean('needs_calibration')->default(false)->after('glass_position');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('needs_calibration');
        });
    }
};
