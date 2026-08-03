<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Per-tech opt-in for the AI scheduling negotiation (see
            // docs/ai-scheduling-spec.md). A tech who isn't opted in is never
            // contacted by an AI scheduling session. Off by default — nobody
            // gets automated texts about a job they didn't ask to receive.
            $table->boolean('ai_scheduling_enabled')->default(false)->after('eod_summary_texts_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('ai_scheduling_enabled');
        });
    }
};
