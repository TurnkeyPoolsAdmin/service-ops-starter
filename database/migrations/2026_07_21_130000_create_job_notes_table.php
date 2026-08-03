<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM-style note log: one row per note someone leaves on a job, stamped with
 * who wrote it and when, so a job reads chronologically ("called, no answer" ->
 * "customer called back, wants Thursday").
 *
 * Deliberately separate from `jobs.notes`, which stays the single always-visible
 * scratchpad (the standing note the dispatcher edits in place).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['job_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_notes');
    }
};
