<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Apply the new `view_issues` permission (Issues review queue) to an
     * already-seeded database. It's granted to no role — only a super_admin
     * ever passes it, via Gate::before. RoleSeeder is idempotent
     * (findOrCreate + syncPermissions), so re-running it here is safe on
     * prod and re-runs.
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
