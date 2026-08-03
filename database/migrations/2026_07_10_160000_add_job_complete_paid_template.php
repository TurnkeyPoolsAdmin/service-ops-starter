<?php

use App\Models\MessageTemplate;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the `job_complete_paid` template family (en + es) — the completion text
 * for a job the customer ALREADY paid before the work was marked complete, with
 * no payment paragraph (nothing is owed). seedMissing() only inserts absent
 * rows, so this never clobbers edited copy of the other templates.
 */
return new class extends Migration
{
    public function up(): void
    {
        MessageTemplateSeeder::seedMissing();
    }

    public function down(): void
    {
        MessageTemplate::where('key', 'job_complete_paid')->delete();
    }
};
