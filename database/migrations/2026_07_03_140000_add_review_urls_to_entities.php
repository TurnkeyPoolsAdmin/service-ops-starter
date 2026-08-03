<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-location review links, editable in Admin → Entities. Exposed to SMS
 * templates as {google_review_url} and {yelp_review_url}; the review-drip
 * templates require the Google link before they will queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->string('google_review_url', 500)->nullable()->after('google_ads_customer_id');
            $table->string('yelp_review_url', 500)->nullable()->after('google_review_url');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn(['google_review_url', 'yelp_review_url']);
        });
    }
};
