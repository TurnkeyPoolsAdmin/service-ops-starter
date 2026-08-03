<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Labor-only jobs: the customer already has the glass, we only install it.
     * Everything else in the job's life is normal — the whole parts side (order,
     * prepay, warehouse, part cost) simply doesn't apply.
     */
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->boolean('labor_only')->default(false)->after('glass_position');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('labor_only');
        });
    }
};
