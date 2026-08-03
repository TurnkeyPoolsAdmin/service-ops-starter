<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per job that entered the post-payment review drip (created when
 * dispatch answers "how did the job go?" on Record payment). Drives the
 * Reviews dashboard and the reviews:advance cron. See ReviewDripService for
 * the state machine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained();

            // Dispatch's thumbs up/down at payment time. Down = drip skipped.
            $table->string('sentiment', 10); // up | down
            // active | paused | skipped | canceled | completed | review_left
            $table->string('status', 20)->index();

            // Drip progress stamps.
            $table->timestamp('step1_sent_at')->nullable();
            $table->timestamp('step2_sent_at')->nullable();
            $table->string('call_outcome', 20)->nullable(); // connected | voicemail
            $table->timestamp('call_logged_at')->nullable();
            $table->foreignId('call_logged_by')->nullable()->constrained('employees');
            $table->string('step3_template', 40)->nullable(); // which text followed the call
            $table->timestamp('step3_sent_at')->nullable();
            $table->timestamp('final_sent_at')->nullable();
            $table->timestamp('review_left_at')->nullable();
            $table->timestamp('thank_you_sent_at')->nullable();

            // When the cron should act next (send step 1/2/3 retry or the final).
            $table->timestamp('next_send_at')->nullable()->index();

            $table->timestamp('paused_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('employees');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_requests');
    }
};
