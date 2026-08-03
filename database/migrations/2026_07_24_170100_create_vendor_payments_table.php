<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per parsed memo LINE off IGC's "Invoice Payment/Returns History"
 * export — the Payments/Credits/Returns cell on an invoice row can hold
 * several memos separated by newlines (App\Services\Vendors\Igc\
 * IgcInvoiceHistoryImporter splits and parses each independently), so this
 * is line-granular, not cell-granular.
 *
 * kind = 'payment' (a "Payment of X on DATE by ..." memo) or 'credit' (a
 * "Credit/Return of X on DATE" memo — see IgcNetter for how the SAME
 * physical return produces two credit rows: a positive one on the return's
 * own document and a negative mirror on the original invoice it credits).
 *
 * amount_cents is SIGNED and NEVER abs()'d — a payment can itself be
 * negative (a reversal, e.g. invoice 1890268's "-78.24" payment), and
 * losing that sign breaks both the balance invariant (App\Services\Vendors\
 * Igc\IgcInvoiceHistoryImporter) and charge-group summing (IgcChargeReconciler).
 *
 * fingerprint makes re-import idempotent: re-uploading an overlapping
 * export window (the IGC upload nag explicitly recommends a 21-day pull,
 * wider than the weekly cadence, so overlap is the normal case) must never
 * duplicate a row, but also must never DELETE a row that's already been
 * linked to a Chase transaction (bank_transaction_id) by the charge
 * reconciler — so import is upsert-by-fingerprint, never delete-and-recreate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->id();
            $table->string('supplier'); // App\Enums\PartsSupplier value — 'igc' today, any future line-item-memo vendor could reuse this table
            $table->foreignId('vendor_document_id')->nullable()->constrained('vendor_documents')->nullOnDelete();
            $table->string('kind'); // payment | credit

            // Plain 'Y-m-d' STRING, not an Eloquent date cast — same rationale
            // as vendor_documents.document_date / ad_metrics.date / bank_transactions.date.
            $table->string('payment_date', 10);

            $table->integer('amount_cents'); // SIGNED — see class doc comment
            $table->string('auth_code')->nullable(); // metadata + tiebreak only, NEVER the grouping key (see IgcChargeReconciler)
            $table->text('memo_raw'); // the exact source memo line, for auditability

            // Stamped once IgcChargeReconciler confirms/suggests which Chase
            // charge this payment line belongs to.
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();

            $table->string('fingerprint')->unique();

            $table->timestamps();

            $table->index(['supplier', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payments');
    }
};
