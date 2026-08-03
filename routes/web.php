<?php

use App\Http\Controllers\PlaidLinkController;
use App\Http\Controllers\PodiumOAuthController;
use App\Http\Controllers\ReceiptPdfController;
use App\Http\Controllers\Webhooks\OpenPhoneWebhookController;
use App\Http\Controllers\Webhooks\PodiumWebhookController;
use App\Http\Controllers\Webhooks\RingCentralWebhookController;
use App\Http\Controllers\Webhooks\SalesIntakeWebhookController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

// Podium OAuth consent handshake (admin-initiated; session-authenticated).
Route::middleware('auth')->group(function () {
    Route::get('/podium/oauth/connect', [PodiumOAuthController::class, 'connect'])->name('podium.oauth.connect');
    Route::get('/podium/oauth/callback', [PodiumOAuthController::class, 'callback'])->name('podium.oauth.callback');

    // Plaid Link handshake to connect a bank (Chase). `connect` also handles the
    // OAuth bounce-back (Plaid appends ?oauth_state_id); `exchange` is the AJAX
    // post-back that trades the public_token for a stored access_token.
    Route::get('/plaid/link/connect', [PlaidLinkController::class, 'connect'])->name('plaid.link.connect');
    Route::post('/plaid/link/exchange', [PlaidLinkController::class, 'exchange'])->name('plaid.link.exchange');
});

// On-request PDF receipt: a Laravel temporary signed link texted to the
// customer (see "Receipts" in CLAUDE.md). No auth — the signature is the
// auth; an invalid/expired signature 403s via the `signed` middleware, and an
// unpaid job 404s inside the controller so a link can never expose it.
Route::get('/receipts/{job}', [ReceiptPdfController::class, 'show'])
    ->middleware('signed')
    ->name('receipts.show');

// Inbound SMS webhook (OpenPhone/Quo). CSRF-exempt (see bootstrap/app.php).
Route::post('/webhooks/openphone', OpenPhoneWebhookController::class)
    ->name('webhooks.openphone');

// Podium message events (sales channel). CSRF-exempt (see bootstrap/app.php).
Route::post('/webhooks/podium', PodiumWebhookController::class)
    ->name('webhooks.podium');

// Inbound SMS webhook (RingCentral subscription delivery). CSRF-exempt (see bootstrap/app.php).
Route::post('/webhooks/ringcentral', RingCentralWebhookController::class)
    ->name('webhooks.ringcentral');

// Stripe payment webhook (card payment link paid). CSRF-exempt (see bootstrap/app.php).
Route::post('/webhooks/stripe', StripeWebhookController::class)
    ->name('webhooks.stripe');

// Booked lead pushed in by the sales dashboard. CSRF-exempt (see bootstrap/app.php);
// authenticated by the X-Sales-Signature HMAC instead.
Route::post('/webhooks/sales-intake', SalesIntakeWebhookController::class)
    ->name('webhooks.sales-intake');
