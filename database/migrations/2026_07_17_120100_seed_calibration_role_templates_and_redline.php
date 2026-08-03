<?php

use App\Enums\JobFunction;
use App\Models\Employee;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Apply the calibration role + manage_calibration permission, seed the three
 * new SMS templates (time confirmation w/ cal note, partner outreach, completed
 * confirm), and create the the calibration partner partner login.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        (new RoleSeeder)->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        MessageTemplateSeeder::seedMissing();

        $redline = Employee::updateOrCreate(
            ['email' => 'calibration@glass.local'],
            [
                'name' => 'Calibration Partner',
                'job_functions' => [JobFunction::Office->value],
                'can_login' => true,
                'active' => true,
                // Temporary password — change after first login.
                'password' => Hash::make('password'),
            ],
        );
        $redline->syncRoles('calibration');
    }

    public function down(): void
    {
        // Non-destructive: leave role, templates, and the the calibration partner user in place.
    }
};
