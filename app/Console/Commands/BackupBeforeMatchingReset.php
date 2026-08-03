<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time safety net before the full transaction-matching reset (2026-08-03).
 * Copies every bank_transaction_matches row, and every BankTransaction's
 * current match_status/matched_at/matched_by, into timestamped archive
 * tables via INSERT-from-SELECT — no deletes here. Run this, confirm the
 * printed counts equal the live tables, THEN run the reset that empties
 * bank_transaction_matches and resets bank_transactions' match columns.
 *
 * Archive tables carry no foreign keys — they are a frozen snapshot, not a
 * live relation, so they must not block later changes to jobs/tech
 * settlements/vendor documents/etc.
 */
class BackupBeforeMatchingReset extends Command
{
    protected $signature = 'matching:backup-before-reset';

    protected $description = 'Archive all bank_transaction_matches rows and bank_transactions match state before the matching reset';

    public function handle(): int
    {
        $suffix = now()->format('Ymd_His');
        $matchesTable = "bank_transaction_matches_archive_{$suffix}";
        $stateTable = "bank_transactions_match_state_archive_{$suffix}";

        $this->archiveMatches($matchesTable);
        $this->archiveTransactionState($stateTable);

        $liveMatches = DB::table('bank_transaction_matches')->count();
        $archivedMatches = DB::table($matchesTable)->count();
        $liveTransactions = DB::table('bank_transactions')->count();
        $archivedState = DB::table($stateTable)->count();

        $this->info("Matches archived: {$archivedMatches} into {$matchesTable} (live bank_transaction_matches: {$liveMatches})");
        $this->info("Transaction match state archived: {$archivedState} into {$stateTable} (live bank_transactions: {$liveTransactions})");

        if ($archivedMatches !== $liveMatches) {
            $this->error('Archived match count does not equal the live bank_transaction_matches count. Do NOT proceed with the reset.');

            return self::FAILURE;
        }

        if ($archivedState !== $liveTransactions) {
            $this->error('Archived transaction-state count does not equal the live bank_transactions count. Do NOT proceed with the reset.');

            return self::FAILURE;
        }

        $this->info('Counts match. Safe to proceed with the reset once you have reviewed these tables.');

        return self::SUCCESS;
    }

    private function archiveMatches(string $table): void
    {
        Schema::create($table, function ($blueprint) {
            $blueprint->id();
            $blueprint->unsignedBigInteger('original_id');
            $blueprint->unsignedBigInteger('bank_transaction_id');
            $blueprint->string('kind');
            $blueprint->unsignedBigInteger('job_id')->nullable();
            $blueprint->unsignedBigInteger('job_payment_id')->nullable();
            $blueprint->unsignedBigInteger('tech_settlement_id')->nullable();
            $blueprint->unsignedBigInteger('vendor_document_id')->nullable();
            $blueprint->unsignedBigInteger('legacy_manual_payment_id')->nullable();
            $blueprint->string('misc_category')->nullable();
            $blueprint->string('source')->default('manual');
            $blueprint->text('note')->nullable();
            $blueprint->integer('expected_amount_cents')->nullable();
            $blueprint->text('mismatch_note')->nullable();
            $blueprint->timestamp('original_created_at')->nullable();
            $blueprint->timestamp('original_updated_at')->nullable();
            $blueprint->timestamp('archived_at');
        });

        DB::statement(<<<SQL
            INSERT INTO {$table} (
                original_id, bank_transaction_id, kind, job_id, job_payment_id,
                tech_settlement_id, vendor_document_id, legacy_manual_payment_id,
                misc_category, source, note, expected_amount_cents, mismatch_note,
                original_created_at, original_updated_at, archived_at
            )
            SELECT
                id, bank_transaction_id, kind, job_id, job_payment_id,
                tech_settlement_id, vendor_document_id, legacy_manual_payment_id,
                misc_category, source, note, expected_amount_cents, mismatch_note,
                created_at, updated_at, CURRENT_TIMESTAMP
            FROM bank_transaction_matches
        SQL);
    }

    private function archiveTransactionState(string $table): void
    {
        Schema::create($table, function ($blueprint) {
            $blueprint->id();
            $blueprint->unsignedBigInteger('bank_transaction_id');
            $blueprint->string('match_status');
            $blueprint->timestamp('matched_at')->nullable();
            $blueprint->unsignedBigInteger('matched_by')->nullable();
            $blueprint->timestamp('archived_at');
        });

        DB::statement(<<<SQL
            INSERT INTO {$table} (bank_transaction_id, match_status, matched_at, matched_by, archived_at)
            SELECT id, match_status, matched_at, matched_by, CURRENT_TIMESTAMP
            FROM bank_transactions
        SQL);
    }
}
