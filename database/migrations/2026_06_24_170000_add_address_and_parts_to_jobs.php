<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // Mobile-service address where the work is performed.
            $table->text('service_address')->nullable()->after('vehicle_raw');
            // Full parts list [{label, cost_cents}, ...]. part_number/part_cost_cents
            // remain populated (primary label + summed cost) for display & profit.
            $table->json('parts_json')->nullable()->after('part_number');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn(['service_address', 'parts_json']);
        });
    }
};
