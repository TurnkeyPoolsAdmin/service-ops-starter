<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Three roles:
     *  - super_admin: full access incl. the entire Admin section + ROAS.
     *  - admin: ops + add/edit entities, employees, warehouses, and delete
     *    jobs. CANNOT touch message templates, API keys/integrations, or
     *    reminder timing, has NO ROAS access, and cannot delete the
     *    super_admin user.
     *  - dispatch: intake, jobs board, scheduling, payments, tech payout,
     *    cancel. No `access_admin`, so no AR / Owed from Xpro / Dividends /
     *    Bookkeeping Health, no Settings, no ROAS, no ledger. It DOES get the
     *    Admin → Finance hub, showing the three tiles its ops permissions
     *    carry: End-of-Day Summary + Tech Balances (`record_tech_payout`) and
     *    Insurance Jobs (`record_payment`). That is intended — dispatch pays
     *    techs and records carrier payments.
     *  - finance: the bank-transaction ledger only — match/flag/confirm Chase
     *    transactions. NO ops, NO other admin section.
     *  - calibration: the Calibration section only (partner portal for the calibration partner).
     *    Schedule + mark complete with PDF uploads. NO ops, NO thumbs confirm
     *    (ops does that with manage_jobs).
     *
     * Gate::before grants super_admin everything (see AppServiceProvider), so
     * permissions primarily distinguish the admin, dispatch, finance and
     * calibration roles. `view_issues` (the Issues review queue) is intentionally
     * assigned to NO role below — only a super_admin can ever see it, via the
     * Gate::before bypass, not through this permission being granted to anyone.
     */
    public function run(): void
    {
        $permissions = [
            'access_admin',          // the whole Admin section
            'manage_employees',
            'manage_templates',
            'manage_settings',       // API keys/integrations + reminder timing
            'manage_entities',
            'manage_warehouses',
            'view_roas',             // ROAS dashboard
            'intake_leads',
            'manage_jobs',
            'delete_jobs',           // permanent job removal
            'record_payment',
            'record_tech_payout',
            'cancel_jobs',
            'manage_optouts',
            'use_bank_ledger',       // the bank-transaction ledger (match/flag/confirm)
            'view_issues',           // the Issues review queue — super_admin only, granted to no role
            'manage_calibration',    // Calibration partner portal (schedule + complete)
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $ops = [
            'intake_leads', 'manage_jobs', 'record_payment', 'record_tech_payout',
            'cancel_jobs', 'manage_optouts', 'manage_calibration',
        ];

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $superAdmin->syncPermissions(Permission::all());

        // Admin: ops + entities + employees + warehouses + delete jobs. No
        // templates, settings (apis/timing), or ROAS.
        $admin = Role::findOrCreate('admin', 'web');
        $admin->syncPermissions([
            ...$ops,
            'access_admin', 'manage_entities', 'manage_employees',
            'manage_warehouses', 'delete_jobs',
        ]);

        $dispatch = Role::findOrCreate('dispatch', 'web');
        $dispatch->syncPermissions($ops);

        // Finance: the bank ledger and nothing else. Works reconciliation
        // (match/flag/confirm) without touching ops, settings, or ROAS.
        $finance = Role::findOrCreate('finance', 'web');
        $finance->syncPermissions(['use_bank_ledger']);

        // Calibration partner (the calibration partner): only the Calibration section.
        $calibration = Role::findOrCreate('calibration', 'web');
        $calibration->syncPermissions(['manage_calibration']);
    }
}
