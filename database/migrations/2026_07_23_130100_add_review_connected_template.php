<?php

use App\Models\MessageTemplate;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the `review_connected` template (en + es) - text 1 of the call-first
 * review flow when the very first call (right after completion) actually
 * connected (see ReviewDripService::advance). seedMissing() only inserts
 * absent rows, so it never clobbers owner-edited copy on other templates.
 *
 * Also touches up `review_request_1`'s display NAME only (never its body) -
 * it's no longer only sent "right after payment" (that entry point is gone;
 * text 1 now also covers a no-answer first call or the 7pm auto-fallthrough).
 */
return new class extends Migration
{
    public function up(): void
    {
        MessageTemplateSeeder::seedMissing();

        MessageTemplate::where('key', 'review_request_1')
            ->update(['name' => 'Review — request 1 (no-answer / auto-fallthrough)']);
    }

    public function down(): void
    {
        MessageTemplate::where('key', 'review_connected')->delete();

        MessageTemplate::where('key', 'review_request_1')
            ->update(['name' => 'Review — request 1 (right after payment)']);
    }
};
