<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Issues" review queue: anything the dispatch team does that feels off —
 * deleted jobs, cancelled jobs, bank transactions matched to a tech payout
 * for a different amount than expected. Raised by App\Models\Issue::raise()
 * (idempotent — one OPEN issue per subject+type), cleared by a super admin
 * on the Issues page. `subject` is a nullable morph so an issue can still be
 * displayed (from `details`) even if the underlying record is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // job_deleted | job_canceled | transaction_mismatch
            $table->nullableMorphs('subject');
            $table->json('details')->nullable();
            $table->foreignId('raised_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status')->default('open'); // open | cleared
            $table->foreignId('cleared_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
