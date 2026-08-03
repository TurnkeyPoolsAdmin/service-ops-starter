<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stamps when the "job not marked complete 2h+ after its window" Telegram alert
 * fired for a job, so the every-15-min sweep alerts each overdue job exactly
 * once (SendTelegramOverdueJobs). Nulled again on (re)schedule so a rescheduled
 * job can re-arm. See App\Services\Telegram\OverdueCompletionAlert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->timestamp('overdue_completion_alerted_at')->nullable()->after('work_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('overdue_completion_alerted_at');
        });
    }
};
