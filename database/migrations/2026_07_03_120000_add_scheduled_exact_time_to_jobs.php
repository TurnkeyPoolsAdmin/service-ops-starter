<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // How the confirmed time is phrased to the customer. Default (false) =
            // a 2-hour arrival window ("between 8am–10am"). When true, dispatch has
            // promised an exact time and the SMS reads "at 8am" instead. Only
            // affects the {scheduled_date_time} merge var — not scheduling logic.
            $table->boolean('scheduled_exact_time')->default(false)->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('scheduled_exact_time');
        });
    }
};
