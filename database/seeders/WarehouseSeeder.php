<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'LA Main Warehouse', 'address' => 'Los Angeles, CA'],
            ['name' => 'Mobile / On-site', 'address' => null],
            ['name' => 'Del Mar Depot', 'address' => 'San Diego, CA'],
        ] as $w) {
            Warehouse::updateOrCreate(['name' => $w['name']], $w + ['active' => true]);
        }
    }
}
