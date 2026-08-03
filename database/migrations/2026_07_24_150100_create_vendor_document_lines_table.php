<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per order-history LINE (one glass part / consumable on one
     * order) — the atomic unit that flows order history -> invoice -> credit
     * memo, all joined on the single stable key `order_line` (Mygrant's own
     * "DR#", e.g. "S74263397-1"; unique per the vendor, never reused).
     *
     * A line with no vendor_document_id yet is `pending_pickup` — confirmed
     * at the counter but never picked up, so it never billed (Line_Total was
     * 0 on the CSV). It gets a document once a later import shows it billed.
     */
    public function up(): void
    {
        Schema::create('vendor_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_document_id')->nullable()->constrained('vendor_documents')->nullOnDelete();
            $table->string('supplier');

            $table->string('order_number');
            // The ONLY field joining order history -> invoice -> credit memo
            // (Mygrant's own line-level id, e.g. "S74263397-1"). See class doc.
            $table->string('order_line')->unique();

            // Plain 'Y-m-d' strings — see vendor_documents migration note.
            $table->string('order_date', 10)->nullable();
            $table->string('confirmed_date', 10)->nullable();

            // True whenever Confirmed_Date came in blank. A blank confirmed
            // date means we don't actually know when Mygrant will consider
            // this picked up, so OrderHistoryImporter can't compute a real
            // cut_date for it — it parks the line in the CURRENT open period
            // instead of guessing, and this flag is how a human finds it to
            // chase down the real date. See OrderHistoryImporter's class doc
            // comment for the bug this replaced (a bad Order_Date fallback
            // that silently inflated an already-closed invoice).
            $table->boolean('needs_confirmation_date')->default(false);

            $table->string('customer_po')->nullable();
            $table->string('ordered_by')->nullable();

            $table->string('warehouse_code')->nullable(); // Mygrant's own B-number (MGC_Warehouse_Number)
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            $table->string('part_number');
            // Normalized leading part code (App\Support\PartNumber) so the
            // SAME part matches whether it's written space-delimited
            // ("FW05851 GTY FYG") or glued together the way a job label
            // sometimes is ("FB30470YPY").
            $table->string('part_number_base')->index();
            $table->text('part_description')->nullable();

            // Parsed out of part_description; null for consumables (wiper
            // blades, urethane, primer, moulding, kits — anything with no
            // parseable vehicle prefix).
            $table->unsignedSmallInteger('vehicle_years_from')->nullable();
            $table->unsignedSmallInteger('vehicle_years_to')->nullable(); // null = open-ended ("still current")
            $table->string('vehicle_make')->nullable();
            $table->string('vehicle_model')->nullable();

            $table->decimal('quantity_ordered', 8, 2)->default(0);
            $table->decimal('quantity_delivered', 8, 2)->default(0);
            $table->integer('unit_price_cents')->default(0);
            $table->integer('line_total_cents')->default(0);

            // Denormalized onto the line too (in addition to living on the
            // parent vendor_documents row) so a line's own billing status is
            // readable without a join.
            $table->string('invoice_number')->nullable();
            $table->string('invoice_date', 10)->nullable();
            $table->string('cut_date', 10)->nullable();

            $table->string('status')->default('pending_pickup'); // App\Enums\VendorLineStatus
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('credit_document_id')->nullable()->constrained('vendor_documents')->nullOnDelete();

            // --- Job-matching columns -------------------------------------
            // Created now so the migration doesn't block the NEXT agent's
            // charge/line -> job matcher; every one of these is left null by
            // this PART of the build (ingest only).
            $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->string('match_status')->default('unmatched'); // unmatched | suggested | matched | no_job
            $table->string('match_source')->nullable(); // auto | manual
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('suggested_job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->integer('variance_cents')->nullable();
            $table->string('no_job_reason')->nullable();
            // ---------------------------------------------------------------

            $table->timestamps();

            $table->index(['supplier', 'status']);
            $table->index('match_status');
            $table->index('cut_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_document_lines');
    }
};
