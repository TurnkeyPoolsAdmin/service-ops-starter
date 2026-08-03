<?php

use App\Support\Name;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off backfill for the name auto-capitalization: existing customers pasted
 * in as "JOHN SMITH" (and the job board's customer_name_snapshot copied from
 * them) become "John Smith". Going forward the Customer/Job mutators normalize
 * on write, so this only needs to run once. Mixed-case names are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('customers')->orderBy('id')
            ->chunkById(200, function ($customers) {
                foreach ($customers as $customer) {
                    $first = Name::normalizeCase($customer->first_name);
                    $last = Name::normalizeCase($customer->last_name);

                    if ($first !== $customer->first_name || $last !== $customer->last_name) {
                        DB::table('customers')->where('id', $customer->id)
                            ->update(['first_name' => $first, 'last_name' => $last]);
                    }
                }
            });

        DB::table('jobs')->orderBy('id')
            ->chunkById(200, function ($jobs) {
                foreach ($jobs as $job) {
                    $name = Name::normalizeCase($job->customer_name_snapshot);

                    if ($name !== $job->customer_name_snapshot) {
                        DB::table('jobs')->where('id', $job->id)
                            ->update(['customer_name_snapshot' => $name]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Casing normalization is not reversible (the original casing is gone).
    }
};
