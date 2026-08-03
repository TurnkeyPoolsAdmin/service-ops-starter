<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per Mygrant billing document: a weekly invoice (the running
     * total of everything picked up that cut-off window) or a credit memo
     * (a later return against one or more already-invoiced lines).
     *
     * The CURRENT OPEN billing period (this week's picked-up-but-not-yet-
     * invoiced lines) is also represented as a row here — with
     * document_number/document_date still null — so lines have somewhere to
     * attach and total up before Mygrant actually issues the invoice number.
     * OrderHistoryImporter promotes that row in place (fills document_number)
     * once the invoice number appears in a later CSV pull, rather than
     * creating a second, duplicate document for the same week.
     */
    public function up(): void
    {
        Schema::create('vendor_documents', function (Blueprint $table) {
            $table->id();
            $table->string('supplier'); // App\Enums\PartsSupplier value, e.g. 'mygrant'
            $table->string('type'); // App\Enums\VendorDocumentType: invoice | credit_memo

            // Nullable: the open period has none until Mygrant issues it — see
            // class doc comment above.
            $table->string('document_number')->nullable();

            // document_date / cut_date / settle_date are plain 'Y-m-d' STRINGS,
            // NOT Eloquent date casts — so the value round-trips identically on
            // SQLite (tests) and MySQL (prod), same rationale as
            // ad_metrics.date / bank_transactions.date. A date cast would
            // append a time component and risk a timezone-shifted day.
            $table->string('document_date', 10)->nullable();
            $table->string('cut_date', 10);
            $table->string('settle_date', 10);

            // Signed: an invoice is positive, a credit memo is negative — so a
            // period's net position is a straight sum of its documents.
            $table->integer('net_amount_cents');
            $table->unsignedInteger('line_count')->default(0);

            $table->string('source'); // order_history | pdf | manual

            // Stamped by the (separate, next-agent) charge reconciler once a
            // Chase transaction is matched to this document.
            $table->timestamp('charge_matched_at')->nullable();

            // Manually closing out an old short week that has no credit memo
            // to explain a small gap between what we were charged and what
            // the invoice lines sum to.
            $table->integer('accepted_difference_cents')->nullable();
            $table->text('accepted_note')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('employees')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['supplier', 'type', 'cut_date']);
            // NULL document_number values are never considered equal to each
            // other by MySQL/SQLite, so this still allows any number of open
            // (document_number null) periods to coexist.
            $table->unique(['supplier', 'document_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_documents');
    }
};
