<?php

namespace App\Http\Controllers;

use App\Filament\Pages\Integrations\PlaidIntegration;
use App\Models\PlaidItem;
use App\Services\Plaid\PlaidClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Drives the one-time Plaid Link handshake from Admin → Integrations. The owner
 * clicks "Connect Chase", logs into Chase inside Plaid Link (OAuth — no credentials
 * touch our server), and Plaid hands back a short-lived public_token which we
 * exchange for a durable access_token stored (encrypted) as a PlaidItem.
 *
 * Chase is an OAuth institution, so Link bounces the browser to chase.com and back
 * to `connect` with an `oauth_state_id`. On that return we re-open Link with the
 * SAME link_token (kept in session) — the documented OAuth re-initialisation step.
 */
class PlaidLinkController extends Controller
{
    public function connect(Request $request, PlaidClient $plaid): RedirectResponse|\Illuminate\View\View
    {
        abort_unless($request->user()?->can('manage_settings'), 403);

        if (! $plaid->configured()) {
            return redirect(PlaidIntegration::getUrl())
                ->with('plaid_error', 'Add the Plaid client ID and secret first, then Save.');
        }

        $isOauthReturn = $request->filled('oauth_state_id');

        // On the OAuth bounce-back, reuse the token we created on the first hop.
        // On a fresh start, mint a new one and remember it for the possible bounce.
        if ($isOauthReturn) {
            $linkToken = $request->session()->get('plaid_link_token');
        } else {
            $linkToken = $plaid->createLinkToken(route('plaid.link.connect'));
            $request->session()->put('plaid_link_token', $linkToken);
        }

        if (blank($linkToken)) {
            return redirect(PlaidIntegration::getUrl())
                ->with('plaid_error', 'Could not start Plaid Link — check the Plaid credentials and environment.');
        }

        return view('plaid.link', [
            'linkToken' => $linkToken,
            'isOauthReturn' => $isOauthReturn,
            'exchangeUrl' => route('plaid.link.exchange'),
            'integrationsUrl' => PlaidIntegration::getUrl(),
        ]);
    }

    public function exchange(Request $request, PlaidClient $plaid): JsonResponse
    {
        abort_unless($request->user()?->can('manage_settings'), 403);

        $validated = $request->validate([
            'public_token' => ['required', 'string'],
            'institution_name' => ['nullable', 'string'],
            'institution_id' => ['nullable', 'string'],
        ]);

        $tokens = $plaid->exchangePublicToken($validated['public_token']);
        if ($tokens === null) {
            return response()->json(['ok' => false, 'message' => 'Could not exchange the Plaid token.'], 422);
        }

        $request->session()->forget('plaid_link_token');

        PlaidItem::query()->updateOrCreate(
            ['item_id' => $tokens['item_id']],
            [
                'access_token' => $tokens['access_token'],
                'institution_name' => $validated['institution_name'] ?? null,
                'institution_id' => $validated['institution_id'] ?? null,
                'status' => 'active',
                'last_error' => null,
            ],
        );

        // Kick an initial pull so the ledger isn't empty until the nightly cron.
        Artisan::queue('bank:pull-transactions', ['--item' => $tokens['item_id']]);

        return response()->json(['ok' => true, 'redirect' => PlaidIntegration::getUrl()]);
    }
}
