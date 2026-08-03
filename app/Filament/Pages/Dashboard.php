<?php

namespace App\Filament\Pages;

use App\Support\Timezone;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Default Filament home at /admin.
 *
 * Calibration-only partners (the calibration partner) must NOT be blocked with canAccess()=false
 * here: after login Filament always hits this route (/admin), and a false
 * canAccess is a hard 403. Instead we hide it from their nav and redirect
 * mount() → Calibration.
 */
class Dashboard extends BaseDashboard
{
    public static function canAccess(): bool
    {
        // Any logged-in panel user may hit /admin (middleware already requires
        // canAccessPanel). Blocking calibration partners here was the 403.
        return auth()->check();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ! self::isCalibrationOnlyPartner();
    }

    public function mount(): void
    {
        if (self::isCalibrationOnlyPartner()) {
            $this->redirect(CalibrationJobs::getUrl());
        }
    }

    /** Time-of-day greeting instead of a static "Dashboard" title. */
    public function getHeading(): string | Htmlable | null
    {
        $hour = (int) Timezone::now()->format('G');
        $greeting = match (true) {
            $hour < 5 => 'Working late',
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };

        $firstName = str((string) (auth()->user()?->name ?? ''))->before(' ')->toString();

        return $firstName !== '' ? "{$greeting}, {$firstName}" : $greeting;
    }

    public function getSubheading(): string | Htmlable | null
    {
        return Timezone::now()->format('l, F j').' · LA · SFV · Del Mar';
    }

    /** Partner-style: only manage_calibration, no ops/admin/finance. */
    public static function isCalibrationOnlyPartner(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        return $user->can('manage_calibration')
            && ! $user->can('manage_jobs')
            && ! $user->can('access_admin')
            && ! $user->can('use_bank_ledger')
            && ! $user->can('intake_leads');
    }
}
