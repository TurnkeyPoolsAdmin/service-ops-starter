<?php

use App\Models\MessageTemplate;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the "Schedule w/ AI" negotiation templates (en + es) — see
 * docs/ai-scheduling-spec.md §3. seedMissing() only inserts absent rows, so
 * it never clobbers owner-edited copy on any other template.
 */
return new class extends Migration
{
    private const KEYS = [
        'tech_ai_slot_offer',
        'tech_ai_slot_reiterate',
        'tech_ai_assigned',
        'tech_ai_filled',
        'customer_ai_time_check',
    ];

    public function up(): void
    {
        MessageTemplateSeeder::seedMissing();
    }

    public function down(): void
    {
        MessageTemplate::whereIn('key', self::KEYS)->delete();
    }
};
