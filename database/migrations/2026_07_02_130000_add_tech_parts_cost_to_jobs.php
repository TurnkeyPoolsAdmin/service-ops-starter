<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // Normally we buy the parts ourselves; sometimes a tech fronts the cost
            // out of pocket. When set, this is reimbursed to the tech ON TOP of
            // their labor (shown as its own line on the End-of-Day breakdown).
            // It does NOT affect gross margin/ROAS — part_cost_cents already counts
            // the part; this is just the same cost flowing through the tech. Cents.
            $table->integer('tech_parts_cost_cents')->nullable()->after('additional_labor_reason');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('tech_parts_cost_cents');
        });
    }
};
