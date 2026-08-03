<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Which location a technician is attached to (optional; only meaningful
            // for technicians). Null for office/sales/manager staff.
            $table->foreignId('entity_id')->nullable()->after('phone')
                ->constrained('entities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entity_id');
        });
    }
};
