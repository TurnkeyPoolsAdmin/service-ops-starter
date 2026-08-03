<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A note can be corrected or retracted by its author, but the trail must stay
 * honest: an edited note shows "edited <time>" next to its original timestamp,
 * and a deleted one leaves a tombstone bubble in place (original timestamp
 * kept, body gone) rather than silently vanishing from the job's history.
 *
 * Hence a soft delete, not a hard one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_notes', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('body');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('job_notes', function (Blueprint $table) {
            $table->dropColumn(['edited_at', 'deleted_at']);
        });
    }
};
