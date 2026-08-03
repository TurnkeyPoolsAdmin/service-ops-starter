<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Flag for review" on a bank transaction — a manual marker that pulls a row
     * out for a second look (with an optional note). Flagged rows are highlighted
     * for the super_admin in the ledger; independent of the match_status workflow.
     */
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->boolean('review_flagged')->default(false)->after('matched_by');
            $table->text('review_note')->nullable()->after('review_flagged');
            $table->timestamp('flagged_at')->nullable()->after('review_note');
            $table->foreignId('flagged_by')->nullable()->after('flagged_at')->constrained('employees')->nullOnDelete();

            $table->index('review_flagged');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('flagged_by');
            $table->dropColumn(['review_flagged', 'review_note', 'flagged_at']);
        });
    }
};
