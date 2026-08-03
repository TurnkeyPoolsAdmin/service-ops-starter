<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A settlement reconciles a technician's running balance: the cash they
     * collected from customers (which they hold) netted against the pay the
     * company owes them. One row per settlement event — either the tech zelled
     * the excess back (collected_from_tech) or the company paid them the
     * difference (paid_to_tech). The contributing jobs are stamped tech-paid at
     * the same time, so this table is the audit trail, not the source of the
     * live balance (that's computed from unsettled jobs).
     */
    public function up(): void
    {
        Schema::create('tech_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained('employees');

            // Net that changed hands, as an absolute value; direction names who paid.
            $table->unsignedInteger('amount_cents');
            $table->string('direction');                 // paid_to_tech | collected_from_tech | even

            // Breakdown captured at settle time (for the audit trail / history).
            $table->unsignedInteger('tech_pay_owed_cents')->default(0);
            $table->unsignedInteger('cash_collected_cents')->default(0);
            $table->unsignedInteger('job_count')->default(0);

            // How the net moved (zelle / venmo / cash) + free-text reference/note.
            $table->string('method')->nullable();
            $table->string('reference')->nullable();
            $table->text('note')->nullable();

            $table->timestamp('settled_at');
            $table->foreignId('recorded_by')->nullable()->constrained('employees');
            $table->timestamps();

            $table->index('technician_id');
            $table->index('settled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tech_settlements');
    }
};
