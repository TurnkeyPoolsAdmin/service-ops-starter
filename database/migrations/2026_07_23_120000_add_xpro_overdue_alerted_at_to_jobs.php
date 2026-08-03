<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stamp for the Owed-from-Xpro overdue escalation: set when the one-time Telegram
 * alert fires for an overdue job so `alerts:telegram-xpro-overdue` pings once per
 * job rather than every run. The Issues queue itself is recomputed (no stamp), so
 * this only gates the Telegram side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->timestamp('xpro_overdue_alerted_at')->nullable()->after('xpro_collected_by');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('xpro_overdue_alerted_at');
        });
    }
};
