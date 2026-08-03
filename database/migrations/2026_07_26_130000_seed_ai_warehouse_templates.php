<?php

use App\Enums\TemplateKey;
use App\Models\MessageTemplate;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the two post-booking warehouse texts (en + es): the ask, and the
 * confirmation once the tech's answer resolves to one of our warehouses.
 * seedMissing() only inserts absent rows, so owner-edited copy elsewhere is
 * never clobbered.
 */
return new class extends Migration
{
    private const KEYS = [
        TemplateKey::TechAiWarehouseAsk->value,
        TemplateKey::TechAiWarehouseSet->value,
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
