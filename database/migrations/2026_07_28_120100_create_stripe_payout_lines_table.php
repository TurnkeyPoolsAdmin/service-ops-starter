<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Stripe balance transaction inside a resolved payout — charges,
 * refunds, and adjustments alike, kept as Stripe reports them (not filtered
 * to charges at write time) so nothing bundled into a payout is silently
 * dropped. job_payment_id is only set for a `charge`-type line whose
 * payment_intent matched a job_payments.reference we already have; null
 * means either a non-charge line (refund/adjustment) or a charge we can't
 * place (test-mode noise, a charge outside our booking flow).
 *
 * gross/fee/net are Stripe's own numbers for that one balance transaction —
 * this replaces the estimate in BankTransactionsTable::expectedNetCentsForAmount()
 * for any payout resolved this way; the estimate remains only for the
 * pre-payout preview shown before a real payout exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_payout_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stripe_payout_id')->constrained('stripe_payouts')->cascadeOnDelete();

            $table->string('stripe_balance_transaction_id'); // txn_...
            $table->string('stripe_charge_id')->nullable(); // ch_...
            $table->string('stripe_payment_intent_id')->nullable(); // pi_... — matched against job_payments.reference

            $table->foreignId('job_payment_id')->nullable()->constrained('job_payments')->nullOnDelete();

            $table->string('type'); // Stripe's own balance_transaction.type: charge | refund | adjustment | ...
            $table->integer('gross_cents');
            $table->integer('fee_cents');
            $table->integer('net_cents');

            $table->timestamps();

            // Idempotency net for resolution running twice on the same payout
            // (e.g. the scheduled retry command racing a backfill run).
            $table->unique(['stripe_payout_id', 'stripe_balance_transaction_id'], 'stripe_payout_lines_payout_txn_unique');
            $table->index('job_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_payout_lines');
    }
};
