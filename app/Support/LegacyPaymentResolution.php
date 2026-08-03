<?php

namespace App\Support;

use App\Models\BankTransaction;
use App\Models\LegacyManualPayment;
use Illuminate\Support\Collection;

/**
 * Pure result of App\Services\LegacyPaymentMatcher::matchOne() — mirrors
 * App\Services\Vendors\ChargeResolution's shape.
 *
 *  - matched      — one or more candidate BankTransactions by amount+date,
 *                   narrowed to exactly one by customer-name tokens in the
 *                   description. Always name-disambiguated, even when there
 *                   was only ever one candidate to begin with (2026-07-30 —
 *                   amount+date proximity alone isn't a safe enough signal
 *                   to attribute someone's money to them).
 *  - ambiguous     — 1+ candidates but name disambiguation didn't narrow to
 *                   one (zero name matches, or 2+ candidates all matching);
 *                   left for a human, never guessed.
 *  - no_candidate — zero BankTransaction rows fit within tolerance/window.
 *
 * $candidates carries the full candidate set that was considered (the
 * winning single row for 'matched', the full set for 'ambiguous', empty
 * for 'no_candidate') — kept for reporting (e.g. showing a human the
 * ambiguous set to eyeball), not needed by matchOne's own control flow.
 */
final readonly class LegacyPaymentResolution
{
    public function __construct(
        public string $state,
        public LegacyManualPayment $payment,
        public ?BankTransaction $transaction = null,
        public Collection $candidates = new Collection(),
    ) {}
}
