<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * Reworks the message-template set in prod (deploy runs `migrate`, never
 * `db:seed`):
 *   - adds the pre-holiday after-hours, holiday, and job-complete templates
 *   - updates the time_confirmation copy to include payment instructions
 *   - removes the review sequence + the standalone Saturday intro (the weekend
 *     Sunday variant now covers Saturday-after-4pm too)
 *
 * firstOrCreate adds only NEW rows, leaving any owner-customized copy untouched.
 * The time_confirmation body is force-updated since adding payment info is the
 * point of this change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $new = [
            'welcome_dispatch_intro_pre_holidayafterhours' => [
                'name' => 'Welcome — dispatch intro, pre-holiday after-hours (Quo)',
                'body' => "Hi {first_name}, this is the scheduling team at {location_name}. We've got your {vehicle} glass service and your requested time of {preferred_date_time}. We're closed for the holiday tomorrow, so we'll reach out to lock in a time as soon as we're back. Feel free to reply here anytime — talk soon! —{dispatch}",
            ],
            'welcome_dispatch_intro_holiday' => [
                'name' => 'Welcome — dispatch intro, holiday (Quo)',
                'body' => "Hi {first_name}, this is the scheduling team at {location_name}. We've got your {vehicle} glass service and your requested time of {preferred_date_time}. We're closed for the holiday today but will be in touch to get you scheduled as soon as we're back. Feel free to reply here anytime — talk soon! —{dispatch}",
            ],
            'job_complete' => [
                'name' => 'Job complete',
                'body' => 'Hi {first_name}, your {vehicle} glass service with {location_name} is complete — thank you for choosing us! Reply here if you have any questions.',
            ],
        ];

        foreach ($new as $key => $data) {
            MessageTemplate::firstOrCreate(
                ['key' => $key],
                $data + ['channel' => 'sms', 'active' => true],
            );
        }

        // time_confirmation now carries payment instructions.
        MessageTemplate::where('key', 'time_confirmation')->update([
            'body' => "Hi {first_name}, your {vehicle} glass service with {location_name} is confirmed for {scheduled_date_time} with your technician {technician}. Your total is {quote}. Payment is due at the time of service — we accept Zelle, Venmo, card, or cash; reply here for payment details. Reply here if you need to make any changes.",
        ]);

        // Retire the review sequence + the standalone Saturday intro.
        MessageTemplate::whereIn('key', [
            'review_1', 'review_2', 'review_3',
            'welcome_dispatch_intro_saturday',
        ])->delete();
    }

    public function down(): void
    {
        MessageTemplate::whereIn('key', [
            'welcome_dispatch_intro_pre_holidayafterhours',
            'welcome_dispatch_intro_holiday',
            'job_complete',
        ])->delete();
    }
};
