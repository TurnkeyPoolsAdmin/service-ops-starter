<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\Pages\CalibrationJobs;
use App\Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Glass Ops')
            ->login()
            // "Forgot your password?" on the login page → emails a reset link to
            // the employee's `email`. Only login-enabled, active accounts get one
            // (Filament gates the send on canAccessPanel()); tokens live in
            // password_reset_tokens. Delivery uses Resend when configured in
            // Admin → Integrations (falls back to MAIL_* / log otherwise).
            ->passwordReset()
            // Note: Filament login redirects to Filament::getUrl() (/admin = Dashboard),
            // not homeUrl. Calibration-only partners are redirected from Dashboard::mount().
            ->homeUrl(function (): ?string {
                if (\App\Filament\Pages\Dashboard::isCalibrationOnlyPartner()) {
                    return CalibrationJobs::getUrl();
                }

                return null;
            })
            // "Tempered glass" look: azure primary (windshield-edge blue) with
            // cool Slate neutrals. The rest of the theme is hand-written CSS in
            // resources/css/filament/glass-ops.css (registered in
            // AppServiceProvider) — there is no Tailwind theme build here.
            ->colors([
                'primary' => Color::generatePalette('#0e7ea8'),
                'gray' => Color::Slate,
            ])
            ->font('Instrument Sans')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2.15rem')
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                'Operations',
                'Admin',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // No AccountWidget: the dashboard header greets by name now, and
            // sign-out lives in the topbar user menu. The default Filament
            // "Welcome"/docs widget (FilamentInfoWidget) stays hidden too.
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
        // The `gd-flagged-row` amber highlight for review-flagged bank rows now
        // lives in resources/css/filament/glass-ops.css with the rest of the theme.
    }
}
