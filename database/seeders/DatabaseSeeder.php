<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            EntitySeeder::class,
            WarehouseSeeder::class,
            MessageTemplateSeeder::class,
            SettingSeeder::class,
            EmployeeSeeder::class,
        ]);
    }
}
