<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Services\Receipts\ReceiptPdfService;
use Illuminate\Http\Response;

/**
 * Serves the on-request PDF receipt from a temporary signed link (see
 * "Receipts" in CLAUDE.md). No auth — the signature IS the auth; an
 * expired/tampered URL 403s via the `signed` route middleware before this
 * ever runs. A job with no recorded payment 404s so a link can never expose
 * an unpaid job's details.
 */
class ReceiptPdfController extends Controller
{
    public function show(Job $job, ReceiptPdfService $receipts): Response
    {
        abort_unless($job->isPaid(), 404);

        return new Response($receipts->pdf($job)->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="receipt-'.$job->id.'.pdf"',
        ]);
    }
}
