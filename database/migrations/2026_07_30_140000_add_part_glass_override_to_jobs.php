<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The part-number vs glass-position mismatch is DERIVED (see
     * App\Support\PartNumberGlass + Job::partGlassMismatch) — there is no
     * "mismatch" column, because fixing either value has to make it go away on
     * its own. What IS stored is the override: dispatch's explicit "I know these
     * don't agree, schedule it anyway", which needs a note and lands on the
     * super-admin Issues queue for a manager.
     *
     * part_glass_override_signature pins the override to the exact mismatch that
     * was overridden ("W|Front Left Door"), so editing the job into a DIFFERENT
     * mismatch re-raises the prompt instead of inheriting an old excuse.
     */
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->timestamp('part_glass_override_at')->nullable()->after('labor_only');
            $table->foreignId('part_glass_override_by')->nullable()->after('part_glass_override_at')
                ->constrained('employees')->nullOnDelete();
            $table->text('part_glass_override_note')->nullable()->after('part_glass_override_by');
            $table->string('part_glass_override_signature', 191)->nullable()->after('part_glass_override_note');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('part_glass_override_by');
            $table->dropColumn([
                'part_glass_override_at', 'part_glass_override_note', 'part_glass_override_signature',
            ]);
        });
    }
};
