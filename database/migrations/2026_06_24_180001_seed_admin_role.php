<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Apply the admin role + new permissions (view_roas, delete_jobs) to an
     * already-seeded database. RoleSeeder is idempotent (findOrCreate +
     * syncPermissions), so running it here is safe on prod and re-runs.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        (new RoleSeeder)->run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Non-destructive: leave roles/permissions in place.
    }
};
