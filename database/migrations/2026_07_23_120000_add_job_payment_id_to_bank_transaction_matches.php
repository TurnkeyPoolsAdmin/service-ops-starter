<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank reconciliation becomes LINE-aware for customer payments. A job_payment
 * match used to point only at the whole Job — fine when a job had one payment,
 * ambiguous once a job could be paid in installments (deposit + balance,
 * possibly different methods/days) since one Chase deposit is really settling
 * ONE line, not "the job". `job_payment_id` lets a match point at the exact
 * line it reconciles; it's nullable so a legacy job-level match (made before
 * this column existed) still reads fine — App\Services\BankMatcher and the
 * ledger's manual Match modal treat "job_payment_id IS NULL on a job_payment
 * match" as a legacy whole-job link and route around it (never double-offer
 * a job that's already spoken for that way).
 *
 * Backfill: a job_payment match whose job has exactly ONE job_payments line
 * unambiguously reconciles to that line, so it's linked up. A job with
 * several lines stays null — we can't guess which installment an old match
 * was for, and it keeps working as a legacy whole-job link either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transaction_matches', function (Blueprint $table) {
            $table->foreignId('job_payment_id')->nullable()->after('job_id')
                ->constrained('job_payments')->nullOnDelete();
        });

        DB::table('bank_transaction_matches')
            ->where('kind', 'job_payment')
            ->whereNotNull('job_id')
            ->orderBy('id')
            ->get(['id', 'job_id'])
            ->each(function ($match) {
                $lines = DB::table('job_payments')->where('job_id', $match->job_id)->pluck('id');

                if ($lines->count() === 1) {
                    DB::table('bank_transaction_matches')
                        ->where('id', $match->id)
                        ->update(['job_payment_id' => $lines->first()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('bank_transaction_matches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_payment_id');
        });
    }
};
