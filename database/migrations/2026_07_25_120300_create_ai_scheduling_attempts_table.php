<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One technician contacted within an ai_scheduling_session — see
 * docs/ai-scheduling-spec.md §2.2. A tech may only have one attempt in a
 * "live" status (queued/asked/negotiating) across ALL sessions at once; that
 * invariant is enforced by app code (TechOutreach), not by this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_scheduling_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('ai_scheduling_sessions')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');

            // 1-based order this tech was/would be contacted in.
            $table->unsignedInteger('position');

            // queued | asked | negotiating | accepted | declined | no_response
            // | skipped | escalated | superseded
            $table->string('status')->default('queued');

            $table->unsignedInteger('reiterations')->default(0);
            // open | light | busy — TechDayLoad::bucket() snapshot at ask time.
            $table->string('load_bucket')->nullable();

            $table->timestamp('asked_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            // 'H:i' — the time this tech actually agreed to, once accepted.
            $table->string('agreed_time')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->string('skip_reason')->nullable();

            $table->timestamps();

            $table->index(['session_id', 'position']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_scheduling_attempts');
    }
};
