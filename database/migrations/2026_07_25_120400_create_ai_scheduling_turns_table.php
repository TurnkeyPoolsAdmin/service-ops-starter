<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The transcript + audit trail for an AI scheduling session — every outbound
 * text, inbound reply, and system note, plus the raw classification and cost
 * of any LLM call involved. This is the product during internal testing (the
 * simulate-mode reply console renders straight from this table). See
 * docs/ai-scheduling-spec.md §2.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_scheduling_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('ai_scheduling_sessions')->cascadeOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('ai_scheduling_attempts')->nullOnDelete();

            // tech | customer | system
            $table->string('party');
            // out | in | note
            $table->string('direction');

            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('scheduled_message_id')->nullable()->constrained('scheduled_messages')->nullOnDelete();
            $table->foreignId('inbound_message_id')->nullable()->constrained('inbound_messages')->nullOnDelete();

            $table->text('body')->nullable();
            // Raw LLM classification JSON, when used_llm is true.
            $table->json('classification')->nullable();
            $table->string('intent')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();

            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('cost_micro')->nullable();
            // false for a deterministic fast-path hit (accept/decline
            // keywords) — most replies never reach the LLM at all.
            $table->boolean('used_llm')->default(false);

            $table->timestamp('created_at')->useCurrent();

            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_scheduling_turns');
    }
};
