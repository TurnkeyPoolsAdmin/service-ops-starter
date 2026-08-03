<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Owed from Xpro" tracking. When a job is paid with a Klarna / SwiftSwipe /
 * Other "- Xpro Team" method, the Xpro team collected the customer's money on
 * our behalf and we still have to collect it back from them. This stamps when
 * that money has been collected from Xpro (until then the job shows on the
 * Owed from Xpro finance page + the weekly Friday Telegram alert).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->timestamp('xpro_collected_at')->nullable()->after('payment_confirmed_by');
            $table->foreignId('xpro_collected_by')->nullable()->after('xpro_collected_at')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('xpro_collected_by');
            $table->dropColumn('xpro_collected_at');
        });
    }
};
