<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IGC's "Invoice Payment/Returns History" export carries its own running
 * Balance column per invoice (total plus every signed credit/return minus
 * every signed payment against it) — nullable here since it's an IGC-only
 * concept; stays null on every Mygrant document.
 *
 * ⚠️ IGC has NO billing cycle (unlike Mygrant's weekly cut/settle math —
 * see BillingCycle). Rules §1.3: observed gaps between orders of 4, 7, 11,
 * 11, 13, 32, and 59 days, on ordinary weekdays — there is no pattern to
 * model. One order = one invoice, issued immediately. So an IGC document
 * always sets cut_date = settle_date = document_date (all three the plain
 * 'Y-m-d' string the order/invoice posted on). Never build a calendar rule
 * for IGC the way BillingCycle exists for Mygrant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_documents', function (Blueprint $table) {
            $table->integer('balance_cents')->nullable()->after('net_amount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_documents', function (Blueprint $table) {
            $table->dropColumn('balance_cents');
        });
    }
};
