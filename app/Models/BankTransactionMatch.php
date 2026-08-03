<?php

namespace App\Models;

use App\Enums\BankMatchKind;
use App\Enums\PartsSupplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One link between a bank transaction and what it actually was: a job's customer
 * payment, a job's parts purchase, a tech's End-of-Day payout, or a plain misc
 * category. A transaction can carry several rows (one vendor charge covering
 * parts for two jobs = two rows). Whether the link is confirmed lives on the
 * transaction's match_status, not here — source only records who created the
 * link (the auto-matcher vs a human).
 */
class BankTransactionMatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => BankMatchKind::class,
        ];
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /**
     * The exact JobPayment line this match reconciles (job_payment kind only).
     * Null on a legacy match made before line-aware matching existed, or on any
     * non-job_payment kind — job() + the job_id are still what everything else
     * (AR, the "Matched to" link, hasBankConfirmedPayment) reads.
     */
    public function jobPayment(): BelongsTo
    {
        return $this->belongsTo(JobPayment::class);
    }

    /**
     * The pre-Job-tracking-era record this match reconciles (job_payment kind
     * only, pre-Job-era matches — job_id/job_payment_id are both null on
     * these). See App\Models\LegacyManualPayment.
     */
    public function legacyManualPayment(): BelongsTo
    {
        return $this->belongsTo(LegacyManualPayment::class);
    }

    public function techSettlement(): BelongsTo
    {
        return $this->belongsTo(TechSettlement::class);
    }

    /**
     * The vendor document this match reconciles (vendor_invoice kind only) — a
     * Mygrant invoice or credit memo. See App\Services\Vendors\VendorChargeReconciler.
     */
    public function vendorDocument(): BelongsTo
    {
        return $this->belongsTo(VendorDocument::class);
    }

    /** Human-readable one-liner for the ledger's "Matched to" column. */
    public function describe(): string
    {
        return match ($this->kind) {
            BankMatchKind::JobPayment => $this->job_id === null && $this->legacy_manual_payment_id !== null
                ? 'Payment (legacy) · '.($this->legacyManualPayment?->customer_name ?? 'deleted record')
                : 'Payment · '.($this->job?->job_number ?? 'deleted job')
                    .($this->job?->customer_name_snapshot ? ' ('.$this->job->customer_name_snapshot.')' : ''),
            BankMatchKind::PartPurchase => 'Parts · '.($this->job?->job_number ?? 'deleted job'),
            BankMatchKind::VendorInvoice => $this->vendorInvoiceLabel(),
            BankMatchKind::TechPayout => 'Tech payout · '.($this->techSettlement?->technician?->name ?? 'deleted')
                .($this->techSettlement?->for_date ? ' ('.$this->techSettlement->for_date.')' : ''),
            BankMatchKind::Dividend => 'Dividend · '.($this->dividendRecipientName() ?? 'owner'),
            BankMatchKind::Misc => BankMatchKind::miscCategories()[$this->misc_category] ?? 'Misc',
        };
    }

    /**
     * For a dividend match, the owner's display name — the recipient key is
     * stored in misc_category (reused; a dividend has no misc category of its own).
     */
    public function dividendRecipientName(): ?string
    {
        return BankMatchKind::dividendRecipients()[$this->misc_category] ?? null;
    }

    /**
     * "Mygrant Invoice I05061146-0" / "Import Glass Corp (IGC) Invoice
     * 1924343" — prefixed by the document's OWN supplier (not hardcoded to
     * Mygrant) now that IGC shares this same match kind.
     */
    private function vendorInvoiceLabel(): string
    {
        $document = $this->vendorDocument;
        if ($document === null) {
            return 'Vendor document';
        }

        $supplier = PartsSupplier::tryFrom($document->supplier ?? '')?->label();

        return trim(($supplier ?? 'Vendor').' '.$document->documentLabel());
    }
}
