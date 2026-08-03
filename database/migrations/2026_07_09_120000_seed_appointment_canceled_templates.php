<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds the two "Cancel appointment" texts in prod (deploy runs `migrate`, never
 * `db:seed`): the customer-facing "canceled — we'll follow up with a new time"
 * notice and the Spanish tech heads-up that a job came off their schedule. Sent
 * from the job's "Cancel appointment" action, which pulls the job off the
 * calendar and back into the scheduling queue. firstOrCreate only adds the row
 * when missing, so owner-customized copy is never clobbered.
 */
return new class extends Migration
{
    public function up(): void
    {
        MessageTemplate::firstOrCreate(
            ['key' => 'appointment_canceled_pending_reschedule'],
            [
                'name' => 'Appointment canceled — awaiting new time',
                'body' => "Hi {first_name}, your {vehicle} glass service with {location_name} has been canceled for now. We'll follow up shortly to get a new date and time that works for you. Reply here with any questions!",
                'channel' => 'sms',
                'active' => true,
            ],
        );

        MessageTemplate::firstOrCreate(
            ['key' => 'tech_job_canceled'],
            [
                'name' => 'Tech — job canceled / off schedule',
                'body' => "Se canceló un trabajo de tu horario:\n\n• Ciudad: {city}\n• Vehículo: {vehicle}\n• Trabajo: {job_type}\n\nEl cliente va a reagendar más adelante. Ya no necesitas ir por ahora.",
                'channel' => 'sms',
                'active' => true,
            ],
        );
    }

    public function down(): void
    {
        MessageTemplate::whereIn('key', [
            'appointment_canceled_pending_reschedule',
            'tech_job_canceled',
        ])->delete();
    }
};
