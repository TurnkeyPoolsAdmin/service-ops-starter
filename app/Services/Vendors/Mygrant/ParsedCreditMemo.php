<?php

namespace App\Services\Vendors\Mygrant;

/**
 * Pure parse result of a Mygrant credit-memo PDF — no DB access, no side
 * effects. See CreditMemoParser for how it's built and CreditMemoImporter
 * for what happens with it.
 *
 * $drReferences = order_line values referenced by "Originally purchased on
 * DR#" in the source text — their ORDER is not meaningful, see
 * CreditMemoParser's class doc comment for why.
 */
final readonly class ParsedCreditMemo
{
    /**
     * @param  array<int, array{req_no: string, date: string, qty: int, part_number: string, part_number_base: ?string, amount_cents: int}>  $lines
     * @param  array<int, string>  $drReferences
     */
    public function __construct(
        public string $documentNumber,
        public string $documentDate, // Y-m-d
        public int $netAmountCents,  // negative
        public array $lines,
        public array $drReferences,
    ) {}

    public function linesTotalCents(): int
    {
        return array_sum(array_column($this->lines, 'amount_cents'));
    }
}
