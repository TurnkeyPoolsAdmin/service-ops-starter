<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Opt-in per tech: when true, they get one text each evening
            // summarizing that Pacific day's completed work + pay (see
            // App\Services\Messaging\TechEodSummaryScheduler). Off by default —
            // nobody gets a new text they didn't ask for.
            $table->boolean('eod_summary_texts_enabled')->default(false)->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('eod_summary_texts_enabled');
        });
    }
};
