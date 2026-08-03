<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plaid's `authorized_date` — when the transaction was INITIATED/authorized,
     * as opposed to `date` which is when it POSTED/settled. For an outgoing Zelle
     * these can differ by days (sent Jul 10, posts Jul 13). We capture it to see
     * whether Chase actually populates it for Zelle; it's often null on
     * bank-transfer transactions, so it supplements `date`, never replaces it.
     *
     * Stored as a plain 'YYYY-MM-DD' string (NOT a date cast), same rationale as
     * `date`: round-trips identically on SQLite (tests) and MySQL (prod).
     */
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->string('authorized_date', 10)->nullable()->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropColumn('authorized_date');
        });
    }
};
