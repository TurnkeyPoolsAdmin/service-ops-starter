<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cutover for the TranslationStale issue (see SweepJobIssues).
 *
 * Staleness is derived from `updated_at` — a Spanish row is stale when its
 * English sibling was edited more recently. But plenty of live English rows were
 * last written by a MIGRATION, not by the owner (2026_07_16_130000 moved the
 * opt-out language, 2026_07_17_140000 GSM-normalized every body), which left
 * their `updated_at` ahead of the Spanish rows'. Without a baseline the very
 * first sweep would dump ~13 issues onto the queue that nobody actually needs to
 * translate — noise on day one, which is how a review queue gets ignored.
 *
 * So: declare every Spanish row reviewed as of now. Staleness accrues only from
 * real English edits made after this deploy. Same shape as the
 * settle_preexisting_cash_jobs cutover — start the new signal from a clean board.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('message_templates')
            ->where('language', 'es')
            ->update(['updated_at' => now()]);
    }

    public function down(): void
    {
        // Nothing to restore — the old updated_at values carried no meaning.
    }
};
