<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `status`/`sent_at` only ever reflect whether OpenPhone/Quo's API *accepted*
 * the send (a 202) — never whether the carrier actually delivered it. These
 * two columns are filled in later, asynchronously, by the OpenPhone delivery
 * webhook (OpenPhoneWebhookController -> DeliveryWebhookHandler), correlated
 * back to the row via the `provider_message_id` already stored at send time.
 * See App\Services\Telegram\MessageDeliveryFailedAlert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->timestamp('delivery_failed_at')->nullable()->after('delivered_at');
            $table->string('delivery_error')->nullable()->after('delivery_failed_at');
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table) {
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn(['delivered_at', 'delivery_failed_at', 'delivery_error']);
        });
    }
};
