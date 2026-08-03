<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The slot-confirmation step: when a tech accepts a whole part of day we come
 * back with the concrete 2-hour slot we mean to book and hold it here while we
 * wait to see whether they want a different time in that window.
 *
 * Kept separate from `agreed_time` on purpose — that column means "this is
 * what they signed up for" and is only written on acceptance, so a proposal
 * still awaiting an answer must not sit in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_scheduling_attempts', function (Blueprint $table) {
            $table->string('proposed_time')->nullable()->after('agreed_time');
        });
    }

    public function down(): void
    {
        Schema::table('ai_scheduling_attempts', function (Blueprint $table) {
            $table->dropColumn('proposed_time');
        });
    }
};
