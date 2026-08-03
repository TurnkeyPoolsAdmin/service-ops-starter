<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rework tech pay to the End-of-Day-Summary + rolling-cash-balance model.
     *
     * - employees.cash_balance_cents: the running cash a tech OWES us (positive),
     *   i.e. cash they collected beyond the pay they were owed. It rolls over day
     *   to day; an End-of-Day settlement where we'd owe them auto-offsets it, and
     *   it's cleared on Tech Balances when they hand the cash back.
     * - tech_settlements gains the fields the EOD ledger needs: which day it was
     *   for, the record type (eod_payout vs balance_clear), and the balance
     *   before/after so each row is a self-contained audit entry.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->integer('cash_balance_cents')->default(0)->after('fixed_cost_cents');
        });

        Schema::table('tech_settlements', function (Blueprint $table) {
            $table->string('type')->default('eod_payout')->after('technician_id'); // eod_payout | balance_clear
            $table->string('for_date')->nullable()->after('type');                 // Y-m-d (display tz) the payout was for
            $table->integer('balance_before_cents')->default(0)->after('cash_collected_cents');
            $table->integer('balance_after_cents')->default(0)->after('balance_before_cents');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('cash_balance_cents');
        });

        Schema::table('tech_settlements', function (Blueprint $table) {
            $table->dropColumn(['type', 'for_date', 'balance_before_cents', 'balance_after_cents']);
        });
    }
};
