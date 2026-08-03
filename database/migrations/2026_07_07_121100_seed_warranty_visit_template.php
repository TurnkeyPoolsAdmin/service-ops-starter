<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * The one automated warranty text: a generic heads-up when the return visit is
 * scheduled. Deliberately plain language ("take a look at the issue you
 * mentioned") — no "warranty"/"defect" wording. firstOrCreate so an
 * owner-edited copy is never clobbered.
 */
return new class extends Migration
{
    public function up(): void
    {
        MessageTemplate::firstOrCreate(
            ['key' => 'warranty_visit_scheduled'],
            [
                'name' => 'Warranty visit scheduled',
                'body' => "Hi {first_name}, this is {location_name}. We'll be there on {warranty_date_time} to take a look at the issue you mentioned. Reply here with any questions — see you then!",
                'channel' => 'sms',
                'active' => true,
            ],
        );
    }

    public function down(): void
    {
        MessageTemplate::where('key', 'warranty_visit_scheduled')->delete();
    }
};
