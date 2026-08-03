<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cutover for the tech cash-balance feature: existing cash payments must NOT be
 * pulled into the new running balances (the cash is already in the techs'
 * pockets and was reconciled the old way). The balance is derived from
 * unsettled jobs (tech_paid_at null), so we stamp every pre-existing settleable
 * CASH job as tech-paid/completed here. From this point on, only NEW cash jobs
 * (left unsettled) count toward a tech's balance.
 *
 * Non-cash jobs where the tech is still owed pay are deliberately left alone, so
 * they show up in Tech Balances and can be paid out in-app (the per-job "Mark
 * tech paid" action is being removed in the same change).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('jobs')
            ->whereNotNull('work_completed_at')
            ->whereNotNull('payment_received_at')
            ->whereNull('tech_paid_at')
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['canceled', 'lost'])
            ->where('payment_method', 'cash')
            ->update([
                'tech_paid_at' => $now,
                'tech_paid_method' => 'cash',
                'status' => 'completed',
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        // One-time data cutover — not reversible (we can't tell which rows were
        // stamped here vs. settled normally afterward).
    }
};
