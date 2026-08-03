<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // Insurance jobs: the customer pays the deductible (the normal quote /
            // payment flow), and the insurer pays the balance separately later.
            // is_insurance is set at intake; the insurance payment is recorded
            // end-to-end on the Insurance tab when the carrier pays.
            $table->boolean('is_insurance')->default(false)->after('quote_amount_cents');
            $table->integer('insurance_amount_cents')->nullable()->after('is_insurance');
            $table->string('insurance_reference')->nullable()->after('insurance_amount_cents');
            $table->text('insurance_notes')->nullable()->after('insurance_reference');
            $table->timestamp('insurance_paid_at')->nullable()->after('insurance_notes');
            $table->foreignId('insurance_paid_recorded_by')->nullable()->after('insurance_paid_at')
                ->constrained('employees');

            $table->index('is_insurance');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('insurance_paid_recorded_by');
            $table->dropColumn([
                'is_insurance', 'insurance_amount_cents', 'insurance_reference',
                'insurance_notes', 'insurance_paid_at',
            ]);
        });
    }
};
