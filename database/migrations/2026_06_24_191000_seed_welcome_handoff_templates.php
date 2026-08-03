<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds the two welcome-handoff templates into prod. The MessageTemplateSeeder
 * only runs on fresh installs (deploy.sh runs `migrate`, never `db:seed`), and
 * running the full seeder would overwrite the owner's edits to existing
 * templates. firstOrCreate adds the two NEW rows only, leaving everything else
 * — including any owner-customized copy — untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $new = [
            'welcome_dispatch_intro' => [
                'name' => 'Welcome — dispatch intro (Quo)',
                'body' => "Hi {first_name}, this is the scheduling team at {location_name}. We'll be taking care of your {vehicle} glass service from here — we'll text you shortly to lock in a time. Feel free to reply with any questions!",
            ],
            'welcome_podium_handoff' => [
                'name' => 'Welcome — sales→dispatch handoff (Podium)',
                'body' => 'Hi {first_name}, thanks again! Our scheduling team at {location_name} will take it from here and text you shortly from a new number to set up your appointment.',
            ],
        ];

        foreach ($new as $key => $data) {
            MessageTemplate::firstOrCreate(
                ['key' => $key],
                $data + ['channel' => 'sms', 'active' => true],
            );
        }
    }

    public function down(): void
    {
        MessageTemplate::whereIn('key', ['welcome_dispatch_intro', 'welcome_podium_handoff'])->delete();
    }
};
