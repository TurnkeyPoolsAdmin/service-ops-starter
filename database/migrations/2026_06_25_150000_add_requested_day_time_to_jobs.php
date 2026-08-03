<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // Structured breakdown of the requested appointment. Day = Today /
            // Tomorrow / a specific date; time = a specific time or "as soon as
            // possible"; custom = dispatch free-text that overrides day + time.
            // requested_daytime_raw stays the effective string for templates.
            $table->string('requested_day')->nullable()->after('requested_daytime_raw');
            $table->string('requested_time')->nullable()->after('requested_day');
            $table->string('requested_custom')->nullable()->after('requested_time');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn(['requested_day', 'requested_time', 'requested_custom']);
        });
    }
};
