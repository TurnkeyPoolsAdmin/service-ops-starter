<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-session technician contact list: the employee ids to text, in the order
 * the dispatcher dragged them into on the "Schedule w/ AI" modal.
 *
 * Null = no override, fall back to the roster ranking
 * (Employee::scopeOrderByPriority) exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_scheduling_sessions', function (Blueprint $table) {
            $table->json('tech_order')->nullable()->after('customer_mode');
        });
    }

    public function down(): void
    {
        Schema::table('ai_scheduling_sessions', function (Blueprint $table) {
            $table->dropColumn('tech_order');
        });
    }
};
