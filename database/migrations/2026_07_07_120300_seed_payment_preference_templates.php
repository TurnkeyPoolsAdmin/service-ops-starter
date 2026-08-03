<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * Zelle/Venmo payment-instruction texts for the "Mark work complete" payment
 * preference gate. firstOrCreate so an owner-edited copy is never clobbered.
 * {zelle_details}/{venmo_details} come from the entity (Admin → Entities) and
 * are required — the text refuses to queue until they're set.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (self::templates() as $key => $data) {
            MessageTemplate::firstOrCreate(
                ['key' => $key],
                $data + ['channel' => 'sms', 'active' => true],
            );
        }
    }

    public function down(): void
    {
        MessageTemplate::whereIn('key', array_keys(self::templates()))->delete();
    }

    private static function templates(): array
    {
        return [
            'payment_zelle' => [
                'name' => 'Payment info — Zelle',
                'body' => 'Hi {first_name}, thank you! You can send your {quote} payment by Zelle to: {zelle_details}. Please reply here once it\'s sent and we\'ll confirm receipt. — {location_name}',
            ],
            'payment_venmo' => [
                'name' => 'Payment info — Venmo',
                'body' => 'Hi {first_name}, thank you! You can send your {quote} payment by Venmo to: {venmo_details}. Please reply here once it\'s sent and we\'ll confirm receipt. — {location_name}',
            ],
        ];
    }
};
