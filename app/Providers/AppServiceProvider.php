<?php

namespace App\Providers;

use App\Services\AiScheduling\ClaudeLlmClassifier;
use App\Services\AiScheduling\LlmClassifier;
use App\Services\Mail\ResendMailer;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Livewire FileUpload needs tmpfile(); Cloudways FPM often disables it.
        require_once app_path('Support/livewire_tmpfile_polyfill.php');

        // AI scheduling classifies SMS replies through Claude. Bound here so
        // ReplyInterpreter resolves the real classifier in production while
        // tests can swap in a fake without touching the network. The
        // classifier self-gates on the ai_scheduling_enabled setting + key,
        // so binding it is not the same as switching the feature on.
        $this->app->bind(LlmClassifier::class, ClaudeLlmClassifier::class);
    }

    public function boot(): void
    {
        // super_admin bypasses all authorization checks.
        Gate::before(function ($user, $ability) {
            return $user->hasRole('super_admin') ? true : null;
        });

        // NOTE: we deliberately do NOT set a global FilamentTimezone. Storage is
        // UTC and every operator-facing date/time column sets ->timezone(
        // Timezone::display()) explicitly. A global default would double-convert
        // the scheduling form's date/time pickers, which pass Pacific wall-clock
        // values and do their own UTC conversion in JobActions::schedule.

        $this->configureResendMailer();

        // The "Tempered Glass" admin theme. Hand-written vanilla CSS (no
        // Tailwind build — see CLAUDE.md); `php artisan filament:assets`
        // publishes it under public/css/app/, and both deploy paths already
        // run that command.
        //
        // ⚠️ The asset id carries a CONTENT HASH. Filament versions asset URLs
        // with `?v=<filament version>`, which never changes between our
        // deploys — so browsers kept serving a stale cached copy after a CSS
        // fix (the 2026-07-29 modal-scroll bug stayed "broken" on every
        // machine that had the old file cached). Hashing the id changes the
        // published FILENAME whenever the file changes, which busts every
        // cache. Old hashed copies left on the server are unreferenced and
        // harmless; public/css/app/ is gitignored like Filament's other
        // published assets.
        $themeCss = resource_path('css/filament/glass-ops.css');
        FilamentAsset::register([
            Css::make(
                'glass-ops-'.(is_file($themeCss) ? substr((string) hash_file('md5', $themeCss), 0, 8) : 'fallback'),
                $themeCss,
            ),
        ]);
    }

    /**
     * Filament's "Forgot password?" flow (and any other framework notification
     * with a mail channel) sends through Laravel's default mailer. Rather than
     * introduce a second, .env-only credential, reuse the same Resend API key
     * already entered in Admin → Integrations → Email / Resend (encrypted in
     * the `settings` table) by pointing the framework's `mail` config at it.
     * Falls back to MAIL_MAILER/.env (whatever that is — log locally) when
     * Resend isn't configured yet, or if the settings table isn't reachable
     * (e.g. mid-migration).
     */
    private function configureResendMailer(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $mailer = $this->app->make(ResendMailer::class);

            if (! $mailer->configured()) {
                return;
            }

            config([
                'mail.default' => 'resend',
                'services.resend.key' => $mailer->apiKey(),
                'mail.from.address' => $mailer->fromAddress(),
                'mail.from.name' => $mailer->fromName(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AppServiceProvider: could not configure Resend mailer.', ['error' => $e->getMessage()]);
        }
    }
}
