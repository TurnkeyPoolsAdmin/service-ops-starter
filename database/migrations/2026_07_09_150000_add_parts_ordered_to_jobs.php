<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stamps when the glass part was ordered for a job (JobLifecycle::markPartsOrdered)
 * — drives the "Parts to Order" page and the post-schedule order prompt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->timestamp('parts_ordered_at')->nullable()->after('warehouse_other');
            $table->foreignId('parts_ordered_by')->nullable()->after('parts_ordered_at')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parts_ordered_by');
            $table->dropColumn('parts_ordered_at');
        });
    }
};
