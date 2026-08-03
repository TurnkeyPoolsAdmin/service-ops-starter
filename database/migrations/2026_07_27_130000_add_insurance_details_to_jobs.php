<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // The claim's own identity + what the carrier said it would pay, so an
            // insurance job can be chased end-to-end. The ACTUAL payout is the
            // pre-existing insurance_amount_cents (written when the check lands);
            // expected - actual is the outstanding insurance receivable.
            $table->string('insurance_carrier')->nullable()->after('is_insurance');
            $table->string('insurance_claim_number')->nullable()->after('insurance_carrier');
            $table->integer('insurance_expected_cents')->nullable()->after('insurance_claim_number');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn([
                'insurance_carrier', 'insurance_claim_number', 'insurance_expected_cents',
            ]);
        });
    }
};
