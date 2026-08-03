<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe webhook payment stamp. When a customer pays a Stripe Payment Link, the
 * `checkout.session.completed` webhook records the payment on the job and marks
 * it received automatically (no bank matching — Stripe is authoritative for the
 * card). These two columns hold when it landed and Stripe's payment_intent id,
 * and make the webhook idempotent (Stripe retries; a set `stripe_paid_at` means
 * we've already handled it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->timestamp('stripe_paid_at')->nullable()->after('stripe_invoice_created_by');
            $table->string('stripe_payment_ref')->nullable()->after('stripe_paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn(['stripe_paid_at', 'stripe_payment_ref']);
        });
    }
};
