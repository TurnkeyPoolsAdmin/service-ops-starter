<?php

namespace App\Services\Reporting;

/**
 * One cell of the ROAS report: the accumulated spend/leads (from Google Ads)
 * and revenue/profit (from booked jobs) for a given location + week, plus the
 * derived ratios. All money is integer cents; "leads" are Google Ads conversions.
 */
class RoasMetrics
{
    public function __construct(
        public int $spendCents = 0,
        public float $leads = 0.0,
        public int $revenueCents = 0,
        public int $profitCents = 0,
    ) {}

    public function add(int $spendCents = 0, float $leads = 0.0, int $revenueCents = 0, int $profitCents = 0): void
    {
        $this->spendCents += $spendCents;
        $this->leads += $leads;
        $this->revenueCents += $revenueCents;
        $this->profitCents += $profitCents;
    }

    /** Cost per lead in cents, or null when there were no leads (avoids ÷0). */
    public function costPerLeadCents(): ?int
    {
        return $this->leads > 0 ? (int) round($this->spendCents / $this->leads) : null;
    }

    /** Revenue ÷ spend, or null when there was no spend (avoids ÷0). */
    public function roas(): ?float
    {
        return $this->spendCents > 0 ? $this->revenueCents / $this->spendCents : null;
    }

    /** Booked profit net of ad spend, in cents. */
    public function netCents(): int
    {
        return $this->profitCents - $this->spendCents;
    }
}
