<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds the on-request receipt template in prod (deploy runs `migrate`, never
 * `db:seed`). firstOrCreate only adds the missing row, so owner-customized
 * copy is never clobbered. {amount_paid}/{payment_method}/{payment_date} come
 * from the job's recorded payment (see TemplateRenderer::varsForJob).
 */
return new class extends Migration
{
    public function up(): void
    {
        MessageTemplate::firstOrCreate(
            ['key' => 'receipt'],
            [
                'name' => 'Receipt (after payment)',
                'body' => "Hi {first_name}, here's your receipt from {location_name}:\n\nDate: {payment_date}\nVehicle: {vehicle}\nService: {job_type}\nAmount paid: {amount_paid}\nPayment method: {payment_method}\n\nThank you for your business! Reply here if you have any questions.",
                'channel' => 'sms',
                'active' => true,
            ],
        );
    }

    public function down(): void
    {
        MessageTemplate::where('key', 'receipt')->delete();
    }
};
