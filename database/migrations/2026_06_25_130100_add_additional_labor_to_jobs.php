<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // Extra labor charged on top of the tech's fixed cost (e.g. molding,
            // calibration, second tech), with a free-text reason. Subtracts from
            // profit alongside part cost + tech pay. Money in integer cents.
            $table->integer('additional_labor_cents')->nullable()->after('tech_pay_amount_cents');
            $table->string('additional_labor_reason')->nullable()->after('additional_labor_cents');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn(['additional_labor_cents', 'additional_labor_reason']);
        });
    }
};
