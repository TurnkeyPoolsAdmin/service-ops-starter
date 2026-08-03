<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill for the "Klara -> Klarna" payment-method rename (commit a32ea38).
 * That commit renamed the PaymentMethod enum backing value from `klara_xpro`
 * to `klarna_xpro` in code but shipped no data migration, so any job saved
 * with the old spelling threw a ValueError on the enum cast (500 on the job
 * page — hit on job 126). Rewrite the stale value everywhere it can live.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('jobs')->where('payment_method', 'klara_xpro')
            ->update(['payment_method' => 'klarna_xpro']);

        DB::table('jobs')->where('payment_preference', 'klara_xpro')
            ->update(['payment_preference' => 'klarna_xpro']);
    }

    public function down(): void
    {
        // No-op: `klara_xpro` is an invalid enum value, so we never restore it.
    }
};
