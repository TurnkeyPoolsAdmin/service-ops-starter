<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds the three after-hours / weekend dispatch-intro variants into prod
 * (deploy runs `migrate`, never `db:seed`). firstOrCreate adds only the new
 * rows, leaving any owner-customized templates untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $new = [
            'welcome_dispatch_intro_afterhours' => [
                'name' => 'Welcome — dispatch intro, after-hours (Quo)',
                'body' => "Hi {first_name}, this is the scheduling team at {location_name}. We've got your {vehicle} glass service and will reach out first thing in the morning to lock in a time. Feel free to reply here anytime — talk soon! —{dispatch}",
            ],
            'welcome_dispatch_intro_saturday' => [
                'name' => 'Welcome — dispatch intro, Saturday after-hours (Quo)',
                'body' => "Hi {first_name}, this is the scheduling team at {location_name}. We've got your {vehicle} glass service request — our team will follow up to schedule with you shortly. Feel free to reply here anytime! —{dispatch}",
            ],
            'welcome_dispatch_intro_sunday' => [
                'name' => 'Welcome — dispatch intro, Sunday (Quo)',
                'body' => "Hi {first_name}, this is the scheduling team at {location_name}. We've received your {vehicle} glass service request and will be in touch to get you scheduled. Feel free to reply here anytime — talk soon! —{dispatch}",
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
        MessageTemplate::whereIn('key', [
            'welcome_dispatch_intro_afterhours',
            'welcome_dispatch_intro_saturday',
            'welcome_dispatch_intro_sunday',
        ])->delete();
    }
};
