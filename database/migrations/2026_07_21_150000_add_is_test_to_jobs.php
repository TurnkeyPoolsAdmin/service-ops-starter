<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flags a job as a throwaway test row (created by Admin > Settings > "Load a
 * test job"). The only behavior it changes is deletion: a test job is purged
 * outright instead of being soft-deleted into the Issues review queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('parse_warnings_json');
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });
    }
};
