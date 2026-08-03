<?php

namespace App\Services\Receipts;

use App\Models\Job;
use App\Support\Money;
use App\Support\Timezone;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Facades\URL;

/**
 * Builds the on-request PDF receipt/invoice (see "Receipts" in CLAUDE.md).
 *
 * The PDF is generated on the fly from live job data — nothing is written to
 * disk. The customer-facing link is a Laravel temporary signed route valid
 * `config('glass.receipts.link_ttl_days')` days from the moment it's minted;
 * re-sending the receipt later just mints a fresh one, so there is no
 * expired-file cleanup to run.
 */
class ReceiptPdfService
{
    /** A fresh 30-day signed URL to the receipts.show route for this job. */
    public function signedUrlFor(Job $job): string
    {
        return URL::temporarySignedRoute(
            'receipts.show',
            now()->addDays((int) config('glass.receipts.link_ttl_days', 30)),
            ['job' => $job->id],
        );
    }

    /** Rendered receipt HTML — exposed separately from the PDF so tests can
     * assert on markup without parsing PDF bytes. */
    public function html(Job $job): string
    {
        return view('receipts.pdf', $this->data($job))->render();
    }

    /** The dompdf instance for $job, ready to ->stream()/->output(). */
    public function pdf(Job $job): DomPdf
    {
        return Pdf::loadHTML($this->html($job))->setPaper('letter', 'portrait');
    }

    /** View data for the receipt — job/entity/customer facts + the payments ledger. */
    public function data(Job $job): array
    {
        $job->loadMissing(['entity', 'customer', 'payments']);

        $vehicle = trim(implode(' ', array_filter([
            $job->vehicle_year,
            $job->vehicle_make,
            $job->vehicle_model,
        ]))) ?: ($job->vehicle_raw ?? '');

        $payments = $job->payments->map(fn ($payment) => [
            'date' => Timezone::toDisplay($payment->collected_at)?->format('M j, Y') ?? '',
            'method' => $payment->method?->label() ?? '',
            'amount' => Money::format($payment->amount_cents),
        ])->all();

        $addressLines = array_values(array_filter(
            array_map('trim', preg_split('~[\r\n]+~', (string) ($job->entity?->address ?? ''))),
            fn ($line) => $line !== ''
        ));

        return [
            'entityName' => $job->entity?->name ?? '',
            'locationName' => $job->entity?->location_name ?? '',
            'entityAddressLines' => $addressLines,
            'customerName' => $job->customer_name_snapshot ?: ($job->customer?->full_name ?? ''),
            'vehicle' => $vehicle,
            'service' => $job->glass_position ?? '',
            'quote' => $job->quote_amount_cents !== null ? Money::format($job->quote_amount_cents) : null,
            'payments' => $payments,
            'amountPaid' => Money::format($job->amount_collected_cents ?? 0),
            'balanceDue' => $job->balanceDueCents() > 0 ? Money::format($job->balanceDueCents()) : null,
            'isFullyPaid' => $job->isFullyPaid(),
            'receiptDate' => Timezone::now()->format('M j, Y'),
            'jobId' => $job->id,
        ];
    }
}
