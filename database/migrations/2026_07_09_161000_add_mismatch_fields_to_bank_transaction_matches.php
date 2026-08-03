<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tech-payout matches whose bank amount doesn't equal the End-of-Day
 * settlement amount now carry the settlement amount (expected_amount_cents)
 * and the human's written explanation (mismatch_note) — surfaced on the
 * ledger and mirrored into an Issue (transaction_mismatch) for review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transaction_matches', function (Blueprint $table) {
            $table->integer('expected_amount_cents')->nullable()->after('note');
            $table->text('mismatch_note')->nullable()->after('expected_amount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transaction_matches', function (Blueprint $table) {
            $table->dropColumn(['expected_amount_cents', 'mismatch_note']);
        });
    }
};
