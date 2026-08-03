<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('entity_id')->constrained('entities');

            $table->string('template_key');
            $table->string('message_type');   // welcome | confirmation | reminder | review | manual
            $table->string('to_phone_e164');
            $table->string('from_phone_e164'); // single global sending number

            $table->text('rendered_body');

            $table->timestamp('scheduled_for')->nullable();   // UTC; null = immediate
            $table->string('status')->default('queued');      // queued|processing|sent|failed|canceled|skipped
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('skip_reason')->nullable();

            $table->string('provider_message_id')->nullable();
            $table->json('provider_response')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // Guarantees one row per lifecycle event. See ScheduledMessage::makeIdempotencyKey().
            $table->string('idempotency_key')->unique();
            $table->foreignId('created_by')->nullable()->constrained('employees');
            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};
