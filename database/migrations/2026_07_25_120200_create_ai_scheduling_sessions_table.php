<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One "Schedule w/ AI" attempt per job — see docs/ai-scheduling-spec.md §2.1.
 * Only one session is ever RUNNING for a given job at a time (enforced in
 * app code, not a DB constraint — closed sessions accumulate as history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_scheduling_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('jobs')->cascadeOnDelete();

            // draft | running | paused | scheduled | exhausted | canceled
            $table->string('status')->default('draft');

            // SchedulingConstraint::toArray() — the live negotiation target.
            $table->json('constraint');
            // What it started as, before any customer-driven retarget.
            $table->json('original_constraint');

            // simulate | approve | live — separate dials so tech-side can go
            // live while customer-side still waits for a dispatcher click.
            $table->string('tech_mode')->default('simulate');
            // simulate | approve | live | off
            $table->string('customer_mode')->default('off');

            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('warehouse_other')->nullable();

            // Not FK-constrained: ai_scheduling_attempts.session_id points back
            // here, so constraining this the other way round would require a
            // second migration just to break the create-order cycle. The
            // pointer is maintained by app code only.
            $table->unsignedBigInteger('current_attempt_id')->nullable();

            $table->unsignedInteger('retarget_count')->default(0);
            $table->string('paused_reason')->nullable();

            $table->foreignId('resolved_technician_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('scheduled_at_utc')->nullable();

            $table->foreignId('created_by')->constrained('employees');

            // Running LLM spend in micro-cents (1/1,000,000 of a dollar) so
            // sub-cent-per-call costs still sum to something meaningful.
            $table->unsignedBigInteger('cost_cents_micro')->default(0);

            $table->timestamps();

            $table->index(['job_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_scheduling_sessions');
    }
};
