<?php

use App\Enums\TemplateKey;
use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retires `tech_ai_assigned` ("You're all set, this job is yours for
 * {slot_phrase}"). A booked AI job now closes on the full
 * `tech_job_confirmation` card, which names the same slot AND carries the
 * customer's name/phone/address, the vehicle, the part number and the
 * warehouse — so the thin text was one message of pure duplication.
 *
 * ⚠️ The existing scheduled_messages rows must be repointed, not left: the
 * template_key column casts to TemplateKey, and the enum case is gone, so a
 * stale value fatals the moment anything reads that row (the job timeline,
 * the AI session transcript). Same gotcha as migration 2026_07_10_150000.
 * Their rendered_body is untouched, so the history still reads as what was
 * actually sent.
 */
return new class extends Migration
{
    private const RETIRED = 'tech_ai_assigned';

    public function up(): void
    {
        DB::table('scheduled_messages')
            ->where('template_key', self::RETIRED)
            ->update(['template_key' => TemplateKey::TechJobConfirmation->value]);

        MessageTemplate::where('key', self::RETIRED)->delete();
    }

    public function down(): void
    {
        // One-way: the rows repointed above can't be told apart from cards
        // this session sent for real, and the retired copy is in git.
    }
};
