<?php

namespace Database\Seeders;

use App\Enums\JobFunction;
use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Employee::updateOrCreate(
            ['email' => 'admin@glass.local'],
            [
                'name' => 'Super Admin',
                'job_functions' => [JobFunction::Manager->value, JobFunction::Office->value],
                'can_login' => true,
                'active' => true,
                'password' => Hash::make('password'),
            ],
        );
        $admin->syncRoles('super_admin');

        $dispatch = Employee::updateOrCreate(
            ['email' => 'dispatch@glass.local'],
            [
                'name' => 'Dispatch Operator',
                'job_functions' => [JobFunction::Office->value],
                'can_login' => true,
                'active' => true,
                'password' => Hash::make('password'),
            ],
        );
        $dispatch->syncRoles('dispatch');

        $finance = Employee::updateOrCreate(
            ['email' => 'finance@glass.local'],
            [
                'name' => 'Finance Clerk',
                'job_functions' => [JobFunction::Office->value],
                'can_login' => true,
                'active' => true,
                'password' => Hash::make('password'),
            ],
        );
        $finance->syncRoles('finance');

        // Calibration partner portal (the calibration partner). Prod also seeds this user via
        // the calibration migration with the real temporary credentials.
        $redline = Employee::updateOrCreate(
            ['email' => 'calibration@glass.local'],
            [
                'name' => 'Calibration Partner',
                'job_functions' => [JobFunction::Office->value],
                'can_login' => true,
                'active' => true,
                'password' => Hash::make('password'),
            ],
        );
        $redline->syncRoles('calibration');

        // Non-login staff for the salesperson / technician dropdowns.
        $sales = [
            ['name' => 'Rafael Sales', 'fn' => JobFunction::Sales],
            ['name' => 'Dana Closer', 'fn' => JobFunction::Sales],
        ];
        foreach ($sales as $s) {
            Employee::updateOrCreate(
                ['name' => $s['name']],
                ['job_functions' => [$s['fn']->value], 'can_login' => false, 'active' => true],
            );
        }

        $techs = [
            ['name' => 'Marco Installer'],
            ['name' => 'Priya Installer'],
        ];
        foreach ($techs as $t) {
            Employee::updateOrCreate(
                ['name' => $t['name']],
                ['job_functions' => [JobFunction::Technician->value], 'can_login' => false, 'active' => true],
            );
        }
    }
}
