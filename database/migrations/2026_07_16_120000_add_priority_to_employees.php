<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Assignment ranking within the tech's location pool (1 = first choice).
            // Null = unranked, sorts last. Ties are allowed.
            $table->unsignedSmallInteger('priority')->nullable()->after('entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
