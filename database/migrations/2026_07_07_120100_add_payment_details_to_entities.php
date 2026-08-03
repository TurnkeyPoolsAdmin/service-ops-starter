<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-location Zelle/Venmo payment details (Admin → Entities), exposed to SMS
 * templates as {zelle_details}/{venmo_details}. The payment-preference texts
 * refuse to queue until these are set — same pattern as the review URLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->string('zelle_details', 255)->nullable()->after('yelp_review_url');
            $table->string('venmo_details', 255)->nullable()->after('zelle_details');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn(['zelle_details', 'venmo_details']);
        });
    }
};
