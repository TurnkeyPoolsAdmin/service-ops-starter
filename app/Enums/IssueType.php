<?php

namespace App\Enums;

enum IssueType: string
{
    case JobDeleted = 'job_deleted';
    case JobCanceled = 'job_canceled';
    case TransactionMismatch = 'transaction_mismatch';
    // A warranty return visit was scheduled with a tech OTHER than the one who
    // did the original job (someone else was sent to fix it).
    case WarrantyTechChanged = 'warranty_tech_changed';
    // A warranty return visit was given extra tech pay (normally unpaid).
    case WarrantyTechPay = 'warranty_tech_pay';
    // A job was finished but is still unpaid the next day (money left on the table).
    case JobCompletedUnpaid = 'job_completed_unpaid';
    // A job is scheduled for today but its glass part still hasn't been ordered.
    case PartNotOrdered = 'part_not_ordered';
    // The part number's glass letter disagrees with the job's glass position
    // (e.g. an "FW…" windshield part on a door-glass job) and dispatch OVERRODE
    // the warning to get the job scheduled. Event-raised at the override, healed
    // by issues:sweep once the part or the position is corrected.
    case PartGlassMismatch = 'part_glass_mismatch';
    // Money the Xpro team collected on our behalf still hasn't been collected back
    // from them by its deadline (the Wednesday after the Friday it was first owed).
    case XproUncollected = 'xpro_uncollected';
    // An English template body was edited more recently than its Spanish sibling,
    // so the translation may no longer say the same thing.
    case TranslationStale = 'translation_stale';
    // A Mygrant card charge is still short/over/ambiguous against its open
    // documents — condition-shaped (recomputed by issues:sweep), distinct from
    // TransactionMismatch (which is event-raised on a manual mismatched match).
    case MygrantChargeMismatch = 'mygrant_charge_mismatch';
    // A billed Mygrant order-history line has had no job (or "no job") decided
    // for 3+ days — a part bought against no apparent work order.
    case MygrantLineUnmatched = 'mygrant_line_unmatched';
    // An IGC return (credit document) has no original invoice found even
    // after the +/-30 day widened search — rules R3.4 / alert I5. Condition-
    // shaped (recomputed by issues:sweep via App\Services\Vendors\Igc\IgcNetter),
    // like MygrantLineUnmatched. Never observed on the real 202-row export.
    case IgcCreditUnmatched = 'igc_credit_unmatched';
    // A Stripe payout's balance-transaction fetch kept failing (Stripe outage,
    // network) until stripe:resolve-payouts exhausted its retry budget — the
    // ONE event-raised (not condition-shaped/swept) case in this list; see
    // App\Console\Commands\StripeResolvePayouts. Subject is the StripePayout
    // row, not a Job — same non-Job precedent as TranslationStale.
    case StripePayoutResolutionExhausted = 'stripe_payout_resolution_exhausted';
    // A Plaid resync silently rewrote amount_cents/date/authorized_date on a
    // transaction that was already matched or suggested — the match may still
    // be correct (it likely was, at the moment it was made), but the fields it
    // was judged against have since moved. Event-raised at the sync that
    // detects the drift (App\Console\Commands\PullBankTransactions); never
    // auto-unmatched or auto-reversed — a human decides.
    case MatchedTransactionFieldsChanged = 'matched_transaction_fields_changed';

    public function label(): string
    {
        return match ($this) {
            self::JobDeleted => 'Deleted job',
            self::JobCanceled => 'Cancelled job',
            self::TransactionMismatch => 'Mismatched transaction',
            self::WarrantyTechChanged => 'Warranty - different tech',
            self::WarrantyTechPay => 'Warranty - extra tech pay',
            self::JobCompletedUnpaid => 'Completed - still unpaid',
            self::PartNotOrdered => 'Part not ordered',
            self::PartGlassMismatch => 'Part vs glass position - overridden',
            self::XproUncollected => 'Owed from Xpro - overdue',
            self::TranslationStale => 'Spanish needs review',
            self::MygrantChargeMismatch => 'Mygrant charge mismatch',
            self::MygrantLineUnmatched => 'Mygrant line - no job',
            self::IgcCreditUnmatched => 'IGC credit - no original found',
            self::StripePayoutResolutionExhausted => 'Stripe payout - resolution failed',
            self::MatchedTransactionFieldsChanged => 'Matched transaction - fields changed since',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::JobDeleted => 'danger',
            self::JobCanceled => 'warning',
            self::TransactionMismatch => 'info',
            self::WarrantyTechChanged => 'warning',
            self::WarrantyTechPay => 'info',
            self::JobCompletedUnpaid => 'danger',
            self::PartNotOrdered => 'danger',
            self::PartGlassMismatch => 'danger',
            self::XproUncollected => 'danger',
            self::TranslationStale => 'warning',
            self::MygrantChargeMismatch => 'warning',
            self::MygrantLineUnmatched => 'warning',
            self::IgcCreditUnmatched => 'warning',
            self::StripePayoutResolutionExhausted => 'danger',
            self::MatchedTransactionFieldsChanged => 'warning',
        };
    }

    /** Map for Filament select options. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }
}
