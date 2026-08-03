<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Align podium_messages with Podium's actual webhook payload (confirmed against
 * the docs after the initial table was created):
 *  - channel_type: "phone" | "email" | ... from conversation.channel.type
 *  - conversation_uid: stable id of the conversation thread
 *  - event_hash: dedupe key — the webhook has no message id (data.uid is null),
 *    so we hash the event fields and uniquely key on that for idempotency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('podium_messages', function (Blueprint $table) {
            $table->string('channel_type')->nullable()->after('business_phone_e164');
            $table->string('conversation_uid')->nullable()->after('provider_message_id');
            $table->string('event_hash')->nullable()->unique()->after('conversation_uid');
        });
    }

    public function down(): void
    {
        Schema::table('podium_messages', function (Blueprint $table) {
            $table->dropUnique(['event_hash']);
            $table->dropColumn(['channel_type', 'conversation_uid', 'event_hash']);
        });
    }
};
