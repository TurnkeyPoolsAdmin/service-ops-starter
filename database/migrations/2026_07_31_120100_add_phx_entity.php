<?php

use App\Models\Entity;
use Illuminate\Database\Migrations\Migration;

/**
 * Fourth location: PHX — Auto Glass of Scottsdale. The sales dashboard books it
 * (its "Scottsdale" entity maps to ops code `PHX`), so the row has to exist in
 * production; prod never re-runs seeders, hence a migration. EntitySeeder gained
 * the same row for fresh installs.
 *
 * PHX is its OWN tech pool — Entity::techPoolEntityIds() only pools LA + SFV
 * (one shared crew), and every other code falls through to "just me", so
 * Scottsdale techs are never offered an LA/SFV job or vice versa.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Insert-if-missing (not updateOrCreate) so a later rename of the
        // location in Admin > Entities is never clobbered by a re-run.
        Entity::firstOrCreate(
            ['code' => 'PHX'],
            [
                'name' => 'Auto Glass of Scottsdale',
                'location_name' => 'Auto Glass of Scottsdale',
                'active' => true,
            ],
        );
    }

    public function down(): void
    {
        // Deliberately not deleted: jobs may already reference it.
    }
};
