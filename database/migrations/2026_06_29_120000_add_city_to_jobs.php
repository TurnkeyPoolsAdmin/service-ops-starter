<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the customer's CITY on the job as its own column. Intake parses a city
 * but previously folded it into the free-text service address; keeping it
 * separate lets the tech-availability text show "city only" (no street address)
 * and gives the tech digests a reliable city, not a best-effort substring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->string('city')->nullable()->after('service_address');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('city');
        });
    }
};
