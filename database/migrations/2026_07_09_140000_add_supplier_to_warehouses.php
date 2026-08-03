<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which parts supplier a warehouse orders from (mygrant | igc | null = other/
 * dealer/custom) plus the supplier's own warehouse code (e.g. Mygrant "B014"),
 * used to build direct order-search links — see App\Services\Parts\PartsOrderLinks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('supplier', 20)->nullable()->after('address');
            $table->string('supplier_code', 50)->nullable()->after('supplier');
        });

        DB::table('warehouses')->where('name', 'like', '%mygrant%')->update(['supplier' => 'mygrant']);
        DB::table('warehouses')
            ->where(fn ($q) => $q->where('name', 'like', '%igc%')->orWhere('name', 'like', '%import glass%'))
            ->update(['supplier' => 'igc']);
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn(['supplier', 'supplier_code']);
        });
    }
};
