<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per account exposed by a linked Plaid Item — e.g. the Chase login
     * yields a checking row and a credit-card row. Every /transactions/sync response
     * already carries the current balances for these accounts (we previously only
     * read name/mask off it and threw the balances away); this table persists them
     * so the ledger can show at-a-glance account health: cash in checking vs. what's
     * owed on the card.
     *
     * Balances are stored in cents. Plaid's sign convention here (unlike the
     * transaction rows): for a credit account `current` = amount OWED (positive),
     * for a depository account `current` = cash balance. We keep type/subtype so the
     * UI can tell the two apart.
     */
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plaid_item_id')->constrained()->cascadeOnDelete();
            $table->string('plaid_account_id')->unique();     // Plaid's account_id
            $table->string('name')->nullable();               // e.g. "Chase Total Checking"
            $table->string('mask', 8)->nullable();            // last 4
            $table->string('type')->nullable();               // depository | credit | ...
            $table->string('subtype')->nullable();            // checking | savings | credit card | ...
            $table->integer('current_balance_cents')->nullable();   // depository: cash; credit: owed
            $table->integer('available_balance_cents')->nullable(); // depository: after holds; credit: available credit
            $table->integer('limit_cents')->nullable();             // credit limit (credit accounts)
            $table->string('iso_currency_code', 8)->default('USD');
            $table->timestamp('balance_updated_at')->nullable();    // when Plaid last reported these
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
