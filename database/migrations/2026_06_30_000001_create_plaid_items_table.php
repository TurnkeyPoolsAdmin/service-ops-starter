<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Plaid "Item" = one bank login (one set of credentials at one institution).
     * Linking Chase via Plaid Link yields one Item, even if it exposes several
     * accounts (e.g. a checking account and a credit card under the same chase.com
     * login). The access_token is the long-lived secret we use to pull data; the
     * cursor is Plaid's incremental marker for /transactions/sync so each daily
     * pull only fetches what changed.
     */
    public function up(): void
    {
        Schema::create('plaid_items', function (Blueprint $table) {
            $table->id();
            $table->string('item_id')->unique();              // Plaid's item_id
            $table->text('access_token');                     // encrypted at rest (model cast)
            $table->string('institution_name')->nullable();   // e.g. "Chase"
            $table->string('institution_id')->nullable();
            $table->text('cursor')->nullable();               // /transactions/sync incremental cursor
            $table->string('status')->default('active');      // active | error
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plaid_items');
    }
};
