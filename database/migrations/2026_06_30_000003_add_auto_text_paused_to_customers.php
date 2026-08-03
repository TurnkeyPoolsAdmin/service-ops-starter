<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Per-lead pause for AUTOMATED texts (welcome, time confirmation,
            // reminders, completion). Distinct from do_not_text (a hard opt-out):
            // a paused lead still receives manual dispatcher texts and calls.
            $table->boolean('auto_text_paused')->default(false)->after('do_not_text');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('auto_text_paused');
        });
    }
};
