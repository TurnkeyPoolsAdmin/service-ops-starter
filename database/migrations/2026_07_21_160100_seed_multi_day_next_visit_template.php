<?php

use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Seed the multi-day install "next visit is scheduled" template in every
 * language. seedMissing() only inserts absent (key, language) rows, so any
 * owner-edited copy is never clobbered — the same pattern the other template
 * migrations use.
 */
return new class extends Migration
{
    public function up(): void
    {
        MessageTemplateSeeder::seedMissing();
    }

    public function down(): void
    {
        // Copy rows are safe to leave; nothing to reverse.
    }
};
