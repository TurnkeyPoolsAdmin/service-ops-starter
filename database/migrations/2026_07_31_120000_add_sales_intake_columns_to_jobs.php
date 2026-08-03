<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the sales-dashboard auto-intake needs on `jobs`.
 *
 *  - external_source / external_ref: which upstream system booked this lead and
 *    its id over there ("glass-sales-dashboard" + the sales Lead id). The
 *    composite UNIQUE index is the idempotency guarantee — the sender may retry
 *    or re-send the same lead forever and only one job can ever exist for it.
 *    Both are NULL on a manually-intaken job, and SQL treats NULLs as distinct,
 *    so the index never constrains manual intake.
 *
 *  - booking_salesperson_name / _phone: a SNAPSHOT of the booking rep. The sales
 *    dashboard has its own users table, so its reps are not `employees` rows
 *    here — booking_salesperson_id (already nullable) stays NULL on these jobs
 *    and the name/phone travel as plain strings instead. Job::bookingSalespersonName()
 *    reads the snapshot first and falls back to the employee relation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->string('external_source')->nullable()->after('parse_warnings_json');
            $table->string('external_ref')->nullable()->after('external_source');
            $table->unique(['external_source', 'external_ref'], 'jobs_external_source_ref_unique');

            $table->string('booking_salesperson_name')->nullable()->after('booking_salesperson_id');
            $table->string('booking_salesperson_phone')->nullable()->after('booking_salesperson_name');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropUnique('jobs_external_source_ref_unique');
            $table->dropColumn([
                'external_source',
                'external_ref',
                'booking_salesperson_name',
                'booking_salesperson_phone',
            ]);
        });
    }
};
