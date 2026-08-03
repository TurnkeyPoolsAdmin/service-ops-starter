<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-customer SMS language — the "Spanish speaker" toggle on intake and on the
 * job view. Picks which translation of every customer-facing template goes out
 * (see TemplateRenderer). Nullable; the model defaults a null to English
 * (Customer::preferredLanguage), so existing customers keep today's behavior
 * with no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('preferred_language', 2)->nullable()->after('contact_pref');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('preferred_language');
        });
    }
};
