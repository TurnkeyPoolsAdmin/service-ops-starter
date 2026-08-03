<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Build mode: pause all outgoing customer texts. Sets the global kill switch
 * read by MessagingServiceProvider so prod logs instead of sending. Flip it
 * back via Admin → Integrations → "Pause outgoing texts" when we're ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'outgoing_sms_paused'],
            ['value' => '1', 'is_sensitive' => false, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'outgoing_sms_paused')->update(['value' => '0']);
    }
};
