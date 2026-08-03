<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tech reminder digests are addressed to an employee (technician), not a
 * customer, and span multiple jobs/entities. Make customer_id + entity_id
 * nullable and add an employee recipient so a digest can be an outbox row like
 * any other automated send (and ride the same worker/idempotency machinery).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->after('customer_id')
                ->constrained('employees')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->change();
            $table->foreignId('entity_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};
