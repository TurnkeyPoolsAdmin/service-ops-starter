<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds the "appointment rescheduled" customer text in prod (deploy runs
 * `migrate`, never `db:seed`). Sent when a dispatcher changes an already-
 * scheduled job's time/date and opts to notify the customer. firstOrCreate only
 * adds the row when missing, so owner-customized copy is never clobbered.
 */
return new class extends Migration
{
    public function up(): void
    {
        MessageTemplate::firstOrCreate(
            ['key' => 'appointment_rescheduled'],
            [
                'name' => 'Appointment rescheduled',
                'body' => "Hi {first_name}, your {vehicle} glass service with {location_name} has been rescheduled to {scheduled_date_time}. Reply here if that doesn't work or you have any questions.",
                'channel' => 'sms',
                'active' => true,
            ],
        );
    }

    public function down(): void
    {
        MessageTemplate::where('key', 'appointment_rescheduled')->delete();
    }
};
