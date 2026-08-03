<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-employee SMS language (currently only techs use it — picks the English
 * vs. Spanish version of the "job canceled" tech text). Nullable; the model
 * defaults a null to Spanish (Employee::preferredLanguage), so existing techs
 * keep the current Spanish behavior with no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('preferred_language', 2)->nullable()->after('job_functions');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('preferred_language');
        });
    }
};
