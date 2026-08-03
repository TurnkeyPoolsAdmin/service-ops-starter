<?php

namespace Database\Seeders;

use App\Models\Entity;
use Illuminate\Database\Seeder;

class EntitySeeder extends Seeder
{
    public function run(): void
    {
        $entities = [
            ['code' => 'LA', 'name' => 'Auto Glass of LA', 'location_name' => 'Auto Glass of LA'],
            ['code' => 'SFV', 'name' => 'San Fernando Valley Auto Glass', 'location_name' => 'San Fernando Valley Auto Glass'],
            ['code' => 'DelMar', 'name' => 'Del Mar Auto Glass', 'location_name' => 'Del Mar Auto Glass'],
            // Fourth location, added with the sales-dashboard auto-intake (the
            // sales "Scottsdale" entity maps to this code). Production gets it
            // from migration 2026_07_31_120100 instead — prod never re-seeds.
            ['code' => 'PHX', 'name' => 'Auto Glass of Scottsdale', 'location_name' => 'Auto Glass of Scottsdale'],
        ];

        foreach ($entities as $e) {
            Entity::updateOrCreate(['code' => $e['code']], $e + ['active' => true]);
        }
    }
}
