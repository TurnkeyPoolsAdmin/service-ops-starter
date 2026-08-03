<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Subcontractor flat rate a technician charges per job. Pulls into a
            // job's tech pay when the tech is assigned (money in integer cents).
            $table->integer('fixed_cost_cents')->nullable()->after('entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('fixed_cost_cents');
        });
    }
};
