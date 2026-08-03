<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily Google Ads metrics, one row per (entity, local calendar date).
     *
     * Daily grain lets us roll up to any week boundary in the display timezone
     * and re-pull idempotently: the unique (entity_id, date) key means a re-sync
     * of the same day overwrites rather than duplicates. We store cost in the
     * Google-native unit (micros: 1,000,000 micros = 1 currency unit) to avoid
     * rounding on ingest; the reporting layer converts to cents.
     */
    public function up(): void
    {
        Schema::create('ad_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();

            // The local (display-tz) calendar date these metrics cover. Google Ads
            // segments.date is already in the account's own timezone.
            $table->date('date');

            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->decimal('conversions', 12, 2)->default(0);   // "leads" — fractional in Google Ads
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);

            // When this row was last fetched from the provider.
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_metrics');
    }
};
