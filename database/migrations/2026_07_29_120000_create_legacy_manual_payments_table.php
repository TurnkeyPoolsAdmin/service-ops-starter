<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A flat historical record of customer payments collected before Job/
 * JobPayment existed (Sept 2025 - Apr 2026, per a hand-tracked CSV the owner
 * kept during that period) — deliberately NOT a Job or JobPayment, since
 * neither schema existed for this era and backfilling fake Job rows would
 * misrepresent history that never went through the app.
 *
 * Purpose: let old, otherwise-unexplained BankTransaction rows in this date
 * window get accounted for against real records instead of sitting in the
 * "needs review" queue forever, or get the same "pre-tracking, excluded"
 * treatment already used for IGC Layer 4 (jobs older than a cutoff) and
 * Mygrant's coverage-start exclusion when no real candidate exists at all.
 *
 * status is derived at import time (App\Console\Commands\ImportLegacyManualPayments)
 * from the source CSV, not user-editable: pending (attempt matching) |
 * needs_review (source CSV flagged it, or the date couldn't be parsed —
 * held for a human, never auto-matched) | no_match_expected (paid = $0, or
 * a Cash row — Cash never hits the bank feed by this app's own convention)
 * | matched (linked via bank_transaction_match_id) | unmatched (matching
 * was attempted but found no confident candidate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_manual_payments', function (Blueprint $table) {
            $table->id();

            // The CSV's own line number (2 = first data row) — idempotency key
            // for reruns of the importer, and a direct pointer back to the
            // source file for anyone auditing a row later.
            $table->unsignedInteger('source_row')->unique();

            // Plain 'Y-m-d' string, not a date cast — same convention as
            // bank_transactions.date elsewhere in this app. Null when the
            // source date was blank/unparseable (e.g. "TBD") — raw_date keeps
            // whatever text was actually in the file so nothing is silently
            // dropped.
            $table->string('payment_date', 10)->nullable();
            $table->string('raw_date')->nullable();

            $table->string('customer_name');
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->string('tech')->nullable();

            $table->integer('charged_cents')->nullable();
            $table->integer('paid_cents')->nullable();
            $table->integer('balance_cents')->nullable();

            // Raw CSV text, deliberately NOT cast to App\Enums\PaymentMethod —
            // this predates that schema and the values don't line up 1:1
            // (e.g. "Card" here isn't necessarily what PaymentMethod::Stripe
            // means today).
            $table->string('payment_method')->nullable();

            // The source CSV's own needs_review column, verbatim.
            $table->text('csv_review_note')->nullable();

            $table->string('status')->default('pending');
            // THIS app's reasoning for the status (distinct from
            // csv_review_note, which is what the CSV's author wrote).
            $table->text('status_note')->nullable();

            $table->foreignId('bank_transaction_match_id')->nullable()
                ->constrained('bank_transaction_matches')->nullOnDelete();

            $table->timestamps();

            $table->index('status');
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_manual_payments');
    }
};
