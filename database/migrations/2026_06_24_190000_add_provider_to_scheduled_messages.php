<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table) {
            // Per-row outbound provider override (openphone|podium). Null = use
            // the globally-configured provider (Admin → Integrations). Lets a
            // single lifecycle event (the welcome handoff) send one text via
            // Podium and the next via Quo regardless of the global default.
            $table->string('provider')->nullable()->after('from_phone_e164');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
