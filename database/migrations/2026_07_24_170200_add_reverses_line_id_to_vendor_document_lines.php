<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line-level counterpart to vendor_documents' document-level credit_document_id:
 * the negative (returned) line points at the positive line it reverses, once
 * both sides of a return are known at the LINE level (part code + amount) —
 * see the IGC rules PART 5 and App\Services\Vendors\Igc\IgcNetter's class doc
 * for how document-level pairing (credit doc <-> original invoice, built off
 * vendor_payments memo mirrors) narrows down to this line-level pairing once
 * order-history lines (part codes) exist for both sides of the pair.
 *
 * Nullable self-FK on vendor_document_lines — a plain line has no reversal;
 * only ever set on the RETURNED (negative) line, pointing at its original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_document_lines', function (Blueprint $table) {
            $table->foreignId('reverses_line_id')->nullable()->after('credit_document_id')
                ->constrained('vendor_document_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_document_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reverses_line_id');
        });
    }
};
