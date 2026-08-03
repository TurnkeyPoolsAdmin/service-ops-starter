<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds the on-demand Stripe card-payment-link template in prod (deploy runs
 * `migrate`, never `db:seed`). firstOrCreate only adds it when missing, so
 * owner-edited copy is never clobbered. {payment_link} is the hosted Stripe
 * pay-by-card URL and {payment_total} the quote + card surcharge — both are set
 * on the job right before the text is queued.
 */
return new class extends Migration
{
    public function up(): void
    {
        MessageTemplate::firstOrCreate(
            ['key' => 'payment_link'],
            [
                'name' => 'Card payment link (Stripe)',
                'body' => "Hi {first_name}, here's a secure link to pay for your {vehicle} glass service with {location_name} by card: {payment_link} Total: {payment_total} (includes a 3% card processing fee). Reply here with any questions!",
                'channel' => 'sms',
                'active' => true,
            ],
        );
    }

    public function down(): void
    {
        MessageTemplate::where('key', 'payment_link')->delete();
    }
};
