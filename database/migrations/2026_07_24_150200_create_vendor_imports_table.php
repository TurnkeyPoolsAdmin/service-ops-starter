<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Audit trail: one row per uploaded order-history CSV / credit-memo PDF. */
    public function up(): void
    {
        Schema::create('vendor_imports', function (Blueprint $table) {
            $table->id();
            $table->string('supplier');
            $table->string('kind'); // order_history | credit_memo
            $table->string('filename');
            $table->foreignId('uploaded_by')->nullable()->constrained('employees')->nullOnDelete();

            $table->unsignedInteger('lines_parsed')->default(0);
            $table->unsignedInteger('lines_created')->default(0);
            $table->unsignedInteger('lines_updated')->default(0);
            $table->unsignedInteger('documents_touched')->default(0);
            // Rows this run saw with a blank Confirmed_Date (see
            // vendor_document_lines.needs_confirmation_date) — surfaced here
            // so an operator sees at a glance whether this upload left
            // anything parked in an open period on a guess.
            $table->unsignedInteger('lines_needing_confirmation_date')->default(0);

            $table->string('result'); // ok | failed
            $table->text('error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_imports');
    }
};
