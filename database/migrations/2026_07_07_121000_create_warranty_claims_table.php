<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warranty / fix-needed return visits on completed jobs. A claim is opened from
 * the job's actions when a customer reports an issue, lives on the Warranty
 * dashboard (notes + scheduling an unpaid tech return visit + calendar), sends
 * ONE generic text when the visit is scheduled, and is resolved when fixed.
 * No money moves through a claim — the original job's payment/payout stand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained();

            // open | scheduled | resolved | canceled
            $table->string('status', 20)->index();

            // What the customer reported + running internal notes.
            $table->text('issue');
            $table->text('notes')->nullable();

            // The unpaid return visit (same shape as the job's appointment).
            $table->foreignId('technician_id')->nullable()->constrained('employees');
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->boolean('scheduled_exact_time')->default(false);
            $table->unsignedTinyInteger('scheduled_window_hours')->nullable();
            $table->foreignId('scheduled_by')->nullable()->constrained('employees');

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('employees');
            $table->text('resolution_notes')->nullable();

            $table->timestamp('canceled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('employees');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
    }
};
