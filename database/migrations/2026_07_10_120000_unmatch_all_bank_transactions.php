<?php

use App\Enums\IssueType;
use App\Models\BankTransaction;
use App\Models\Issue;
use Illuminate\Database\Migrations\Migration;

/**
 * One-time reset requested after the matching-rule changes landed (2026-07-09):
 * wipe every existing match/suggestion so the team re-reviews the ledger fresh
 * under the new rules, instead of trusting stale auto-matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        BankTransaction::query()
            ->where('match_status', '!=', BankTransaction::STATUS_UNMATCHED)
            ->each(function (BankTransaction $record) {
                $record->matches()->delete();
                $record->update([
                    'match_status' => BankTransaction::STATUS_UNMATCHED,
                    'matched_at' => null,
                    'matched_by' => null,
                ]);

                Issue::clearOpenFor($record, IssueType::TransactionMismatch);
            });
    }

    public function down(): void
    {
        // Irreversible data reset — no-op.
    }
};
