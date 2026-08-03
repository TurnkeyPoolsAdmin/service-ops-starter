<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds the Spanish tech-availability outreach template into prod (deploy runs
 * `migrate`, never `db:seed`). firstOrCreate adds only the new row, leaving any
 * owner-customized copy untouched. Sent to technicians (not the customer) from
 * the Quo number after-hours/Sundays/holidays to start the "can you take this?"
 * conversation; intentionally shows CITY ONLY — no address, name, or phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        MessageTemplate::firstOrCreate(
            ['key' => 'tech_job_availability'],
            [
                'name' => 'Tech availability request (Spanish)',
                'body' => "Hola, tengo un trabajo nuevo.\n\n• Ciudad: {city}\n• Preferencia de horario: {preferred_date_time}\n• Vehículo: {vehicle}\n• Parte: {part_number}\n• Trabajo: {job_type}\n\n¿Lo puedes hacer? ¿A qué hora podrías atenderlo? Y si sí, ¿a qué tienda quieres que pidamos la pieza?",
                'channel' => 'sms',
                'active' => true,
            ],
        );
    }

    public function down(): void
    {
        MessageTemplate::where('key', 'tech_job_availability')->delete();
    }
};
