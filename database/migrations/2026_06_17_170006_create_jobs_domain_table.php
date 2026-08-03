<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_number')->unique();   // e.g. LA-2026-0001
            $table->foreignId('entity_id')->constrained('entities');

            $table->foreignId('customer_id')->constrained('customers');
            $table->string('customer_name_snapshot')->nullable();
            $table->string('customer_phone_snapshot')->nullable();

            // Vehicle / glass
            $table->string('vehicle_year')->nullable();
            $table->string('vehicle_make')->nullable();
            $table->string('vehicle_model')->nullable();
            $table->string('vin')->nullable();
            $table->string('glass_position')->nullable();
            $table->text('vehicle_raw')->nullable();

            // Part / quote (money in integer cents)
            $table->string('part_number')->nullable();
            $table->integer('part_cost_cents')->nullable();
            $table->boolean('part_available')->nullable();
            $table->integer('quote_amount_cents')->nullable();

            // People
            $table->foreignId('booking_salesperson_id')->nullable()->constrained('employees');
            $table->foreignId('technician_id')->nullable()->constrained('employees');

            // Location of work
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses');
            $table->string('warehouse_other')->nullable();

            // Scheduling (all stored UTC; computed in display tz)
            $table->string('requested_daytime_raw')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('scheduled_timezone')->default('America/Los_Angeles');
            $table->timestamp('last_scheduled_at')->nullable();
            $table->unsignedInteger('rescheduled_count')->default(0);
            $table->foreignId('scheduled_by')->nullable()->constrained('employees');

            // Lifecycle
            $table->string('status')->default('pending_schedule');
            $table->timestamp('work_completed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->string('lost_reason')->nullable();

            // Payment (orthogonal fact)
            $table->integer('amount_collected_cents')->nullable();
            $table->string('payment_method')->nullable();      // zelle | venmo | cash
            $table->string('payment_reference')->nullable();
            $table->text('payment_notes')->nullable();
            $table->timestamp('payment_received_at')->nullable();
            $table->foreignId('payment_recorded_by')->nullable()->constrained('employees');

            // Tech payout (orthogonal fact)
            $table->integer('tech_pay_amount_cents')->nullable();
            $table->string('tech_paid_method')->nullable();
            $table->string('tech_paid_reference')->nullable();
            $table->timestamp('tech_paid_at')->nullable();
            $table->foreignId('tech_paid_recorded_by')->nullable()->constrained('employees');

            // Review
            $table->boolean('ask_for_review')->default(true);
            $table->timestamp('review_started_at')->nullable();

            // Intake / parse
            $table->longText('intake_raw_text')->nullable();
            $table->json('parsed_payload_json')->nullable();
            $table->string('parse_status')->default('clean');   // clean | warnings | failed
            $table->json('parse_warnings_json')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('employees');
            $table->softDeletes();
            $table->timestamps();

            // Indexes for board filters / reporting
            $table->index(['customer_id', 'status']);
            $table->index('entity_id');
            $table->index('status');
            $table->index('scheduled_at');
            $table->index('payment_received_at');
            $table->index('tech_paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
