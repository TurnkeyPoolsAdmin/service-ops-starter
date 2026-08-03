<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments ledger — one row per payment collected on a job, so a job can be
 * paid in PARTIAL installments (a deposit now, the balance later) and possibly
 * across different methods. Before this, a job had a single collected amount +
 * method; now those columns on `jobs` become a maintained SUMMARY of this ledger
 * (`amount_collected_cents` = sum of the lines, `payment_method` = the latest
 * line's method) so every downstream consumer (AR, bank matching, ROAS,
 * receipts) keeps reading the same "total collected" it always did.
 *
 * Backfills one line per already-paid job from its existing summary columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('jobs')->cascadeOnDelete();
            $table->integer('amount_cents');
            $table->string('method'); // PaymentMethod value
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('collected_at');
            $table->timestamps();

            $table->index('job_id');
        });

        // Backfill: each job that already has a recorded payment becomes one line,
        // built straight from its summary columns (raw query — no model booting).
        DB::table('jobs')
            ->whereNotNull('payment_received_at')
            ->orderBy('id')
            ->get(['id', 'amount_collected_cents', 'payment_method', 'payment_reference', 'payment_notes', 'payment_recorded_by', 'payment_received_at'])
            ->each(function ($job) {
                DB::table('job_payments')->insert([
                    'job_id' => $job->id,
                    'amount_cents' => (int) ($job->amount_collected_cents ?? 0),
                    'method' => $job->payment_method ?: 'zelle',
                    'reference' => $job->payment_reference,
                    'notes' => $job->payment_notes,
                    'recorded_by' => $job->payment_recorded_by,
                    'collected_at' => $job->payment_received_at,
                    'created_at' => $job->payment_received_at,
                    'updated_at' => $job->payment_received_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_payments');
    }
};
