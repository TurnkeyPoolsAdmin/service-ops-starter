<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // "Text card payment link": a Stripe invoice (quote + card surcharge,
            // one line item) whose hosted pay-by-card page we text the customer.
            // The customer's own payment is still recorded through recordPayment
            // (method = Stripe/CC) — these columns just track the sent link.
            $table->string('stripe_customer_id')->nullable()->after('payment_recorded_by');
            $table->string('stripe_invoice_id')->nullable()->after('stripe_customer_id');
            $table->text('stripe_invoice_url')->nullable()->after('stripe_invoice_id');
            $table->integer('stripe_invoice_amount_cents')->nullable()->after('stripe_invoice_url');
            $table->string('stripe_invoice_status')->nullable()->after('stripe_invoice_amount_cents');
            $table->timestamp('stripe_invoice_created_at')->nullable()->after('stripe_invoice_status');
            $table->foreignId('stripe_invoice_created_by')->nullable()->after('stripe_invoice_created_at')
                ->constrained('employees');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stripe_invoice_created_by');
            $table->dropColumn([
                'stripe_customer_id', 'stripe_invoice_id', 'stripe_invoice_url',
                'stripe_invoice_amount_cents', 'stripe_invoice_status', 'stripe_invoice_created_at',
            ]);
        });
    }
};
