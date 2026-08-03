<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per Plaid transaction across all linked bank/credit accounts.
     *
     * Idempotent on plaid_transaction_id: re-pulling the same transaction (Plaid
     * may re-send a "modified" version, e.g. a pending charge that later posts)
     * overwrites rather than duplicates. Account identity is denormalised onto each
     * row (name + mask) so the ledger needs no separate accounts table.
     *
     * Sign convention follows Plaid: `amount` is POSITIVE when money leaves the
     * account (a purchase / debit / outgoing) and NEGATIVE for money in (a deposit /
     * refund / payment received). We store signed integer cents and let the UI label it.
     */
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plaid_item_id')->constrained('plaid_items')->cascadeOnDelete();

            $table->string('plaid_transaction_id')->unique();
            $table->string('plaid_account_id')->index();
            $table->string('account_name')->nullable();       // e.g. "Chase Total Checking"
            $table->string('account_mask', 8)->nullable();    // last 4 of the account number

            $table->integer('amount_cents');                  // signed; see class note
            $table->string('iso_currency_code', 8)->nullable()->default('USD');

            // Plaid returns 'YYYY-MM-DD'. Stored as a plain string (NOT a date cast)
            // so the value round-trips identically on SQLite (tests) and MySQL (prod),
            // mirroring ad_metrics.date. A date cast would append a time component.
            $table->string('date', 10);

            $table->string('name');                           // transaction description
            $table->string('merchant_name')->nullable();
            $table->string('category')->nullable();           // personal_finance_category.primary
            $table->string('payment_channel')->nullable();    // online | in store | other
            $table->boolean('pending')->default(false);

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('date');
            $table->index(['plaid_item_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
