<?php

namespace App\Enums;

/**
 * Where a job's recorded non-cash customer payment stands against the bank ledger.
 *
 *  - AwaitingMatch  — payment collected, but no Chase transaction confirmed to it yet.
 *  - Confirmed      — a matched transaction (or matched set) reconciles to the amount.
 *  - AmountMismatch — a transaction is matched, but the amounts don't line up.
 *
 * Cash and unpaid jobs have no transaction-match status (null) — cash never hits
 * the bank, and there's nothing to match until a payment is recorded.
 */
enum TransactionMatchStatus: string
{
    case AwaitingMatch = 'awaiting_transaction_match';
    case Confirmed = 'transaction_confirmed';
    case AmountMismatch = 'transaction_amount_mismatch';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingMatch => 'Awaiting transaction match',
            self::Confirmed => 'Transaction confirmed',
            self::AmountMismatch => 'Transaction amount mismatch',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AwaitingMatch => 'warning',
            self::Confirmed => 'success',
            self::AmountMismatch => 'danger',
        };
    }
}
