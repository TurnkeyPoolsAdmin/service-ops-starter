<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Stripe payout, created the moment the `payout.paid` webhook
 * fires (StripeWebhookController), then resolved shortly after by fetching
 * the payout's balance transactions (App\Services\Stripe\StripePayoutResolver)
 * — see App\Models\StripePayoutLine for the per-charge detail.
 *
 * Two-phase by design: the payout row itself (id/amount/arrival_date) is
 * cheap and always saved from the webhook payload alone; resolving which
 * job_payments it covers requires a second Stripe API call that can fail
 * independently (network blip, Stripe outage) without losing the payout
 * record. resolution_status tracks that second phase so a failed resolution
 * can be retried (stripe:resolve-payouts) without re-processing the webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_payout_id')->unique(); // po_...
            $table->integer('amount_cents'); // Stripe's own payout amount — what lands at Chase
            $table->string('currency', 3)->default('usd');

            // Plain 'Y-m-d' STRING, not a date cast — same convention as
            // bank_transactions.date / vendor_documents.settle_date elsewhere
            // in this app, to avoid a timezone-shifted day on round-trip.
            $table->string('arrival_date', 10);

            $table->string('status'); // Stripe's own payout status: paid | failed | canceled

            // Our own resolution state machine — see class doc comment.
            $table->string('resolution_status')->default('pending_resolution');
            $table->unsignedInteger('resolution_attempts')->default(0);
            $table->text('resolution_error')->nullable();
            $table->timestamp('resolved_at')->nullable();

            // Stamped once the actual Chase deposit is matched to this payout
            // — mirrors vendor_payments.bank_transaction_id.
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();

            $table->timestamps();

            $table->index('resolution_status');
            $table->index('arrival_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_payouts');
    }
};
