<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            // The 10-digit Google Ads customer ID for this location's account,
            // stored WITHOUT dashes (e.g. "1234567890"). One account per location.
            $table->string('google_ads_customer_id')->nullable()->after('location_name');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('google_ads_customer_id');
        });
    }
};
