<?php

namespace App\Console\Commands;

use App\Models\BankTransaction;
use App\Models\BankTransactionMatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Step 2 of the full transaction-matching reset (2026-08-03). Run ONLY after
 * matching:backup-before-reset has completed and its counts were verified
 * against the live tables by a human.
 *
 * Deletes every bank_transaction_matches row and resets each
 * bank_transactions row's match_status to unmatched with matched_at/
 * matched_by cleared. Does NOT touch bank_transactions rows themselves or
 * any reference data (Job, JobPayment, LegacyManualPayment, TechSettlement,
 * VendorDocument, etc.) — only the match LINKS are removed.
 */
class ExecuteMatchingReset extends Command
{
    protected $signature = 'matching:execute-reset {--force : Skip the interactive confirmation}';

    protected $description = 'Delete all bank_transaction_matches rows and reset bank_transactions match state to unmatched';

    public function handle(): int
    {
        $matchCount = BankTransactionMatch::count();
        $matchedTransactionCount = BankTransaction::where('match_status', '!=', BankTransaction::STATUS_UNMATCHED)
            ->orWhereNotNull('matched_at')
            ->orWhereNotNull('matched_by')
            ->count();

        $this->info("About to delete {$matchCount} bank_transaction_matches rows.");
        $this->info("About to reset {$matchedTransactionCount} bank_transactions rows to unmatched.");

        if (! $this->option('force')) {
            $confirmed = $this->confirm('Have you already run matching:backup-before-reset and verified the counts match the live tables?', false);

            if (! $confirmed) {
                $this->error('Aborted. Run matching:backup-before-reset first and verify the counts before running this command.');

                return self::FAILURE;
            }
        }

        DB::transaction(function () {
            BankTransactionMatch::query()->delete();

            BankTransaction::query()->update([
                'match_status' => BankTransaction::STATUS_UNMATCHED,
                'matched_at' => null,
                'matched_by' => null,
            ]);
        });

        $remainingMatches = BankTransactionMatch::count();
        $stillMatchedTransactions = BankTransaction::where('match_status', '!=', BankTransaction::STATUS_UNMATCHED)
            ->orWhereNotNull('matched_at')
            ->orWhereNotNull('matched_by')
            ->count();

        $this->info("bank_transaction_matches rows remaining: {$remainingMatches}");
        $this->info("bank_transactions rows not fully reset: {$stillMatchedTransactions}");

        if ($remainingMatches !== 0 || $stillMatchedTransactions !== 0) {
            $this->error('Reset did not fully complete. Investigate before touching any code.');

            return self::FAILURE;
        }

        $this->info('Reset complete. All bank_transaction_matches rows deleted, all bank_transactions rows unmatched.');

        return self::SUCCESS;
    }
}
