<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual "we actually received this payment" confirmation for the Accounts
 * Receivable view. A non-cash payment (Zelle/Venmo/Strip) is recorded as
 * collected, but until a Chase transaction is matched to it — or an admin
 * confirms it here — we haven't verified the money truly landed. This stamps
 * that manual confirmation. Cash is out of scope (tech collects it in person).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->timestamp('payment_confirmed_at')->nullable()->after('payment_received_at');
            $table->foreignId('payment_confirmed_by')->nullable()->after('payment_confirmed_at')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_confirmed_by');
            $table->dropColumn('payment_confirmed_at');
        });
    }
};
