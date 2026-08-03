<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * Cash and Check now text a "Job Complete - …" payment confirmation, same as
 * Zelle/Venmo/Credit Card. Renames the existing three templates' display
 * names only (NEVER the body — an owner-edited body must survive this), and
 * clones the live Zelle body onto two new templates for the owner to rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (self::renames() as $key => $name) {
            MessageTemplate::where('key', $key)->update(['name' => $name]);
        }

        $zelleBody = MessageTemplate::where('key', 'payment_zelle')->value('body')
            ?? "Hi {first_name}, thank you! You can send your {quote} payment by Zelle to: {zelle_details}. Please reply here once it's sent and we'll confirm receipt. — {location_name}";

        foreach (self::newTemplates($zelleBody) as $key => $data) {
            MessageTemplate::firstOrCreate(
                ['key' => $key],
                $data + ['channel' => 'sms', 'active' => true],
            );
        }
    }

    public function down(): void
    {
        MessageTemplate::whereIn('key', array_keys(self::newTemplates('')))->delete();
    }

    private static function renames(): array
    {
        return [
            'payment_zelle' => 'Job Complete - Zelle',
            'payment_venmo' => 'Job Complete - Venmo',
            'payment_link' => 'Job Complete - Credit Card',
        ];
    }

    private static function newTemplates(string $zelleBody): array
    {
        return [
            'payment_cash' => [
                'name' => 'Job Complete - Cash',
                'body' => $zelleBody,
            ],
            'payment_check' => [
                'name' => 'Job Complete - Check',
                'body' => $zelleBody,
            ],
        ];
    }
};
