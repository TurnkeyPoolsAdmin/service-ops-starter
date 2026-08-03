<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers the last observed state of each external connector (Podium, Quo,
 * Google Ads, Plaid, Stripe, RingCentral) so the hourly health sweep can alert
 * on a TRANSITION rather than on every run.
 *
 * Without this the sweep would either spam the group chat once an hour for as
 * long as a credential stays dead, or (if it only alerted once) go silent on
 * the recovery. `last_alerted_status` is the one that gates the alert;
 * `status`/`checked_at` are the live reading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_health', function (Blueprint $table) {
            $table->id();
            $table->string('connector')->unique();
            $table->string('status')->default('unknown');   // ok | down | unknown
            $table->text('error')->nullable();
            $table->string('last_alerted_status')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_health');
    }
};
