<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same "pre-tracking, out of scope" treatment already used for IGC Layer 4
 * (jobs older than a cutoff never alert) and Mygrant's coverage-start
 * exclusion (VendorChargeReconciler::isOutOfScope) — but for a
 * BankTransaction with no real record to explain it at all (e.g. no
 * candidate in the legacy-payments CSV import, App\Services\LegacyPaymentMatcher).
 *
 * Deliberately separate from match_status (unmatched/suggested/matched) —
 * an excluded row is NOT matched (nothing explains it), it's just removed
 * from the active "needs review" queue (BankTransaction::scopeNeedsReview)
 * so it stops demanding attention on a transaction nobody can actually
 * explain anymore. The row and its excluded_reason stay forever, and it's
 * reversible: excluded_at/excluded_reason/excluded_by simply get cleared to
 * bring a transaction back into the active queue if evidence ever surfaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->timestamp('excluded_at')->nullable()->after('matched_by');
            $table->text('excluded_reason')->nullable()->after('excluded_at');
            // Nullable — null means excluded by an automated sweep/import, not a person.
            $table->foreignId('excluded_by')->nullable()->after('excluded_reason')
                ->constrained('employees')->nullOnDelete();

            $table->index('excluded_at');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('excluded_by');
            $table->dropColumn(['excluded_at', 'excluded_reason']);
        });
    }
};
