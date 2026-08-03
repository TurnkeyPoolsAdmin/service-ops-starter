<?php

use App\Models\MessageTemplate;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the `tech_return_part` template (en + es) — the reminder texted to the
 * assigned tech to bring back a part they'd already picked up when the
 * appointment is canceled (see JobLifecycle::cancelAppointment). seedMissing()
 * only inserts absent rows, so edited copy of other templates is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        MessageTemplateSeeder::seedMissing();
    }

    public function down(): void
    {
        MessageTemplate::where('key', 'tech_return_part')->delete();
    }
};
