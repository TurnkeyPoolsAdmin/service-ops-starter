<?php

use App\Support\GsmText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two fixes for the "long payment text silently never delivered" bug:
 *
 *  1. Backfill the LIVE rows. The seeder edit that swapped smart punctuation
 *     for ASCII only changes DEFAULT copy — it never touches template rows the
 *     owner already has, nor bodies already rendered into the outbox. So prod
 *     kept sending the curly-apostrophe job_complete text (UCS-2, ~12 segments)
 *     and it kept getting dropped (Paul 626-390-7227, then Louis 310-694-6553).
 *     This rewrites every existing message_templates.body and every UNSENT
 *     scheduled_messages.rendered_body through GsmText::normalize().
 *
 *  2. Add delivery_unconfirmed_alerted_at. OpenPhone/Quo does NOT send a
 *     message.failed webhook for this failure — it accepts the send (202,
 *     status=sent) and then goes silent: no message.delivered, no
 *     message.failed. So the webhook alert can never fire. Instead a sweep
 *     (alerts:sms-delivery-unconfirmed) flags messages sent but never confirmed
 *     delivered after a grace period; this column stamps the one-time alert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table) {
            $table->timestamp('delivery_unconfirmed_alerted_at')->nullable()->after('delivery_error');
        });

        // 1a. Normalize every existing template body.
        foreach (DB::table('message_templates')->get() as $row) {
            $clean = GsmText::normalize((string) $row->body);
            if ($clean !== $row->body) {
                DB::table('message_templates')->where('id', $row->id)->update(['body' => $clean]);
            }
        }

        // 1b. Normalize unsent outbox bodies so already-queued texts (a
        // job_complete waiting on the every-minute processor) go out as GSM-7.
        // Sent rows are historical — leave them.
        foreach (
            DB::table('scheduled_messages')
                ->whereIn('status', ['queued', 'processing'])
                ->get() as $row
        ) {
            $clean = GsmText::normalize((string) $row->rendered_body);
            if ($clean !== $row->rendered_body) {
                DB::table('scheduled_messages')->where('id', $row->id)->update(['rendered_body' => $clean]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('scheduled_messages', function (Blueprint $table) {
            $table->dropColumn('delivery_unconfirmed_alerted_at');
        });
    }
};
