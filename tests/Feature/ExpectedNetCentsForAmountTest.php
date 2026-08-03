<?php

use App\Filament\Resources\BankTransactions\Tables\BankTransactionsTable;

/**
 * expectedNetCentsForAmount() previously computed Stripe's 2.9% + 30c fee
 * against the job's revenue (JobPayment::amount_cents) directly. That's
 * wrong: the customer's card is actually charged revenue x 1.03 (the
 * surcharge — see JobLifecycle::createAndTextStripeInvoice), and Stripe
 * takes its fee off THAT gross-with-surcharge amount. Computing the fee
 * against revenue alone understated the true net by roughly the surcharge
 * percentage of every line.
 */
function expectedNetCentsForAmount(int $revenueCents): int
{
    $method = new ReflectionMethod(BankTransactionsTable::class, 'expectedNetCentsForAmount');
    $method->setAccessible(true);

    return $method->invoke(null, $revenueCents);
}

it('computes the fee against the gross-with-surcharge charge, not the job revenue', function () {
    // $500 job revenue -> customer actually charged $500 x 1.03 = $515.00.
    // Stripe's cut: 2.9% x $515.00 + $0.30 = $15.24. Net deposited: $499.76.
    // (The old, wrong formula took 2.9% + $0.30 off $500.00 directly ->
    // $485.20, understating the true net by $14.56.)
    expect(expectedNetCentsForAmount(50000))->toBe(49976);
});

it('matches exactly when revenue is zero', function () {
    expect(expectedNetCentsForAmount(0))->toBe(-30);
});

it('scales correctly for a smaller job', function () {
    // $100 revenue -> charged $103.00 -> fee 2.9% x $103.00 + $0.30 = $3.29 (rounded) -> net $99.71.
    expect(expectedNetCentsForAmount(10000))->toBe(9971);
});
