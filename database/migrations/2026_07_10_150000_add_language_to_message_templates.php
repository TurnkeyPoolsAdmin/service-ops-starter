<?php

use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Templates gain a language dimension: one editable row per (key, language)
 * instead of one per key. Existing rows are English.
 *
 * That makes the two language-suffixed keys redundant, so they collapse into
 * one key apiece (carrying any owner-edited copy with them):
 *   tech_job_canceled_en / _es  →  tech_job_canceled  @ en / es
 *   tech_job_availability       →  its body was Spanish, so it becomes the `es`
 *                                  row and an English row is seeded alongside.
 *
 * Finally MessageTemplateSeeder::seedMissing() fills in the Spanish translation
 * of every other template. It only INSERTS, so English copy the owner has
 * already customized is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('language', 2)->default('en')->after('key');
        });

        // One key may now have several rows — uniqueness moves to the pair.
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->unique(['key', 'language']);
        });

        // Collapse the language-suffixed keys, keeping edited bodies.
        $this->repoint('tech_job_canceled_en', 'tech_job_canceled', 'en');
        $this->repoint('tech_job_canceled_es', 'tech_job_canceled', 'es');

        // The one availability template was Spanish-only; it is now the `es` row.
        DB::table('message_templates')
            ->where('key', 'tech_job_availability')
            ->update(['language' => 'es']);

        // Names lose their "(Spanish)" / "(English)" suffixes — the language is
        // its own column now. Bodies are left exactly as they are.
        DB::table('message_templates')->where('key', 'tech_job_canceled')
            ->update(['name' => 'Tech — job canceled / off schedule']);
        DB::table('message_templates')->where('key', 'tech_job_availability')
            ->update(['name' => 'Tech availability request']);

        // Sent/queued rows recorded the old keys; TemplateKey no longer has those
        // cases, so the enum cast would blow up reading them back.
        DB::table('scheduled_messages')->whereIn('template_key', ['tech_job_canceled_en', 'tech_job_canceled_es'])
            ->update(['template_key' => 'tech_job_canceled']);

        // Every Spanish body (plus the English tech-availability row).
        MessageTemplateSeeder::seedMissing();
    }

    /**
     * Move a row to its new key+language, or drop it when the destination is
     * already there (a re-run, or both halves already merged).
     */
    private function repoint(string $oldKey, string $newKey, string $language): void
    {
        $old = DB::table('message_templates')->where('key', $oldKey)->first();
        if (! $old) {
            return;
        }

        $taken = DB::table('message_templates')
            ->where('key', $newKey)->where('language', $language)->exists();

        $taken
            ? DB::table('message_templates')->where('id', $old->id)->delete()
            : DB::table('message_templates')->where('id', $old->id)
                ->update(['key' => $newKey, 'language' => $language]);
    }

    public function down(): void
    {
        // Restore the split keys, then collapse back to one row per key.
        DB::table('message_templates')->where('key', 'tech_job_canceled')->where('language', 'en')
            ->update(['key' => 'tech_job_canceled_en', 'name' => 'Tech — job canceled / off schedule (English)']);
        DB::table('message_templates')->where('key', 'tech_job_canceled')->where('language', 'es')
            ->update(['key' => 'tech_job_canceled_es', 'name' => 'Tech — job canceled / off schedule (Spanish)']);

        DB::table('scheduled_messages')->where('template_key', 'tech_job_canceled')
            ->update(['template_key' => 'tech_job_canceled_es']);

        DB::table('message_templates')->where('key', 'tech_job_availability')->where('language', 'en')->delete();
        DB::table('message_templates')->where('key', 'tech_job_availability')
            ->update(['name' => 'Tech availability request (Spanish)']);

        // Drop every translation — the pre-migration schema holds one row per key.
        DB::table('message_templates')->where('language', 'es')
            ->whereNotIn('key', ['tech_job_canceled_es', 'tech_job_availability'])->delete();

        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropUnique(['key', 'language']);
            $table->unique('key');
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
