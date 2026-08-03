<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a technician's Zelle payout shows up on Chase — e.g. "Zelle payment to
 * Jose Aguirre" → store "Jose Aguirre". Lets BankMatcher::techFor() identify
 * which tech an outgoing Zelle belongs to before it even looks at amounts.
 * Nullable; Employee::zelleMatchName() falls back to the employee's name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('zelle_payout_name')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('zelle_payout_name');
        });
    }
};
