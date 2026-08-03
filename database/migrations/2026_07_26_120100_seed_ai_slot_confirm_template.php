<?php

use App\Enums\TemplateKey;
use App\Models\MessageTemplate;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the slot-confirmation text (en + es) the AI sends after a tech agrees
 * to a whole part of day. seedMissing() only inserts absent rows, so it never
 * clobbers owner-edited copy on any other template.
 */
return new class extends Migration
{
    public function up(): void
    {
        MessageTemplateSeeder::seedMissing();
    }

    public function down(): void
    {
        MessageTemplate::where('key', TemplateKey::TechAiSlotConfirm->value)->delete();
    }
};
