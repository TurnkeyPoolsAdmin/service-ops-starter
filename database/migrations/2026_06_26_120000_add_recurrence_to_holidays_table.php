<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holidays can now recur three ways:
 *  - fixed:    a single explicit calendar date (must be re-added each year)
 *  - annual:   the same month/day every year (e.g. Jan 1)
 *  - relative: the Nth weekday of a month every year (e.g. 3rd Thursday of Nov)
 *
 * Existing rows keep their explicit `date` and default to the `fixed` type, so
 * the holiday dispatch-intro logic is unchanged for already-entered holidays.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the unique constraint first: annual/relative rows carry a NULL
        // date, and we no longer want one-date-per-row enforced at the DB level.
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropUnique('holidays_date_unique');
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->string('type')->default('fixed')->after('id'); // fixed|annual|relative
            $table->unsignedTinyInteger('month')->nullable()->after('date');   // 1-12 (annual + relative)
            $table->unsignedTinyInteger('day')->nullable()->after('month');     // 1-31 (annual)
            $table->unsignedTinyInteger('weekday')->nullable()->after('day');   // 0=Sun..6=Sat (relative)
            $table->tinyInteger('week')->nullable()->after('weekday');          // 1-5, or -1 = last (relative)
            $table->date('date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn(['type', 'month', 'day', 'weekday', 'week']);
        });
    }
};
