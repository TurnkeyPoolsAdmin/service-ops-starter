<?php

use App\Enums\TemplateKey;
use App\Models\MessageTemplate;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the tech's job card (en + es) — the full-detail text the assigned
 * technician gets the moment a job has a date/time and a tech. seedMissing()
 * only inserts absent rows, so it never clobbers owner-edited copy on any
 * other template.
 */
return new class extends Migration
{
    public function up(): void
    {
        MessageTemplateSeeder::seedMissing();
    }

    public function down(): void
    {
        MessageTemplate::where('key', TemplateKey::TechJobConfirmation->value)->delete();
    }
};
