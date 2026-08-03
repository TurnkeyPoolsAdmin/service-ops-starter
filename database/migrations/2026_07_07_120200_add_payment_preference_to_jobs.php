<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the customer said they'll pay, confirmed on "Mark work complete"
 * (zelle | venmo | stripe | cash — the PaymentMethod enum). Drives which
 * payment-instructions text goes out; the actual payment is still recorded
 * through recordPayment as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->string('payment_preference', 20)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('payment_preference');
        });
    }
};
