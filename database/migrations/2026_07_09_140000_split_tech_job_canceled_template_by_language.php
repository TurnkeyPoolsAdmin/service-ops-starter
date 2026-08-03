<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * Splits the single Spanish "tech job canceled" text into English + Spanish
 * versions (the tech's preferred_language now picks one). The prior migration
 * (2026_07_09_120000) seeded `tech_job_canceled` — carry any owner edits to it
 * over to the Spanish key, then add the English key. firstOrCreate never
 * clobbers copy that's already been customized.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Repoint the old Spanish row to its new key (preserving any edits).
        $old = MessageTemplate::where('key', 'tech_job_canceled')->first();
        if ($old && ! MessageTemplate::where('key', 'tech_job_canceled_es')->exists()) {
            $old->update(['key' => 'tech_job_canceled_es', 'name' => 'Tech — job canceled / off schedule (Spanish)']);
        } elseif ($old) {
            // A Spanish row already exists — drop the now-duplicate old row.
            $old->delete();
        }

        MessageTemplate::firstOrCreate(
            ['key' => 'tech_job_canceled_es'],
            [
                'name' => 'Tech — job canceled / off schedule (Spanish)',
                'body' => "Se canceló un trabajo de tu horario:\n\n• Ciudad: {city}\n• Vehículo: {vehicle}\n• Trabajo: {job_type}\n\nEl cliente va a reagendar más adelante. Ya no necesitas ir por ahora.",
                'channel' => 'sms',
                'active' => true,
            ],
        );

        MessageTemplate::firstOrCreate(
            ['key' => 'tech_job_canceled_en'],
            [
                'name' => 'Tech — job canceled / off schedule (English)',
                'body' => "A job was canceled off your schedule:\n\n• City: {city}\n• Vehicle: {vehicle}\n• Job: {job_type}\n\nThe customer will reschedule later. No need to go for now.",
                'channel' => 'sms',
                'active' => true,
            ],
        );
    }

    public function down(): void
    {
        MessageTemplate::where('key', 'tech_job_canceled_en')->delete();
        MessageTemplate::where('key', 'tech_job_canceled_es')
            ->update(['key' => 'tech_job_canceled', 'name' => 'Tech — job canceled / off schedule']);
    }
};
