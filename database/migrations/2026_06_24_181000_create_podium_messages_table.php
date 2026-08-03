<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages captured from Podium (the sales team's customer-messaging channel)
 * via webhook. Keyed by the customer's phone so intake can surface the most
 * recent conversation when a job is created and we take over scheduling.
 *
 * Podium has no read API — this table is the only history we have, populated
 * forward from the moment the webhook is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('podium_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('direction');                 // inbound (from customer) | outbound (from sales)
            $table->string('contact_phone_e164');        // the customer's number — the lookup key
            $table->string('business_phone_e164')->nullable();
            $table->text('body')->nullable();
            $table->timestamp('occurred_at');            // when Podium recorded the message
            $table->string('provider_message_id')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['contact_phone_e164', 'occurred_at']);
            $table->unique('provider_message_id');       // idempotent on webhook redelivery
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('podium_messages');
    }
};
