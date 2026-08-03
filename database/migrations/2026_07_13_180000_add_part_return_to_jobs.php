<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part-return tracking. When an appointment is canceled and the tech had
 * already PICKED UP the glass part, dispatch confirms it on cancel — we stamp
 * `part_return_needed_at` (the tech is texted to bring it back) and the job
 * surfaces on the Parts → "Parts returned" tab until someone marks it returned
 * (`part_returned_at`/`part_returned_by`). See JobLifecycle::cancelAppointment
 * and markPartReturned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->timestamp('part_return_needed_at')->nullable()->after('parts_paid_by');
            $table->timestamp('part_returned_at')->nullable()->after('part_return_needed_at');
            $table->foreignId('part_returned_by')->nullable()->after('part_returned_at')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('part_returned_by');
            $table->dropColumn(['part_return_needed_at', 'part_returned_at']);
        });
    }
};
