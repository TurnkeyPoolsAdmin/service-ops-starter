<?php

namespace App\Enums;

enum BankMatchKind: string
{
    case JobPayment = 'job_payment';     // incoming: customer paid us (zelle / card)
    case PartPurchase = 'part_purchase'; // outgoing: glass vendor charge, tied to job(s)
    case VendorInvoice = 'vendor_invoice'; // outgoing: a vendor's own invoice/credit-memo document(s) — Mygrant (App\Services\Vendors\VendorChargeReconciler) or IGC (App\Services\Vendors\Igc\IgcChargeReconciler)
    case TechPayout = 'tech_payout';     // outgoing: End-of-Day zelle to a technician
    case Dividend = 'dividend';          // outgoing: zelle to an owner (Alex / David)
    case Misc = 'misc';                  // everything else, just categorized

    public function label(): string
    {
        return match ($this) {
            self::JobPayment => 'Customer payment',
            self::PartPurchase => 'Parts purchase',
            self::VendorInvoice => 'Vendor invoice',
            self::TechPayout => 'Tech payout',
            self::Dividend => 'Dividend payout',
            self::Misc => 'Misc',
        };
    }

    /** key => display name for the configured dividend recipients (owners). */
    public static function dividendRecipients(): array
    {
        return (array) config('glass.bank_matching.dividend_recipients', []);
    }

    /** Map for Filament select options. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }

    /** Misc category value => label, for the manual-match form and display. */
    public static function miscCategories(): array
    {
        return [
            'google_ads' => 'Google Ads',
            'cc_payment' => 'Credit-card payment (internal)',
            'bank_fee' => 'Bank fee',
            'back_office_services' => 'Back Office Services',
            'transfer' => 'Transfer',
            'refund' => 'Customer refund',
            'other' => 'Other',
        ];
    }
}
