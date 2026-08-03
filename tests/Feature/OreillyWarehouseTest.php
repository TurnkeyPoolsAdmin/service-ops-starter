<?php

use App\Filament\Resources\BankTransactions\Tables\BankTransactionsTable;
use App\Models\Warehouse;

/**
 * Regression coverage for the O'Reilly's warehouse gap (2026-07-28): a
 * Warehouse row (any Warehouse row, not just this one) must appear in the
 * Bank Transactions Match modal's "Parts purchase" warehouse dropdown —
 * warehouseSelectOptions() lists every Warehouse row by name with no filter
 * beyond ordering, so a row like O Reilly Auto Parts (created by
 * database/migrations/2026_07_28_140000_backfill_oreilly_warehouse.php,
 * itself untested here — see that file's own doc comment for why a
 * hardcoded-production-job-id backfill isn't unit tested, matching this
 * codebase's other one-off data migrations) is guaranteed to show up once it
 * exists, with no separate "did the dropdown pick it up" step to forget.
 */
function warehouseSelectOptionsForTest(): array
{
    $method = new ReflectionMethod(BankTransactionsTable::class, 'warehouseSelectOptions');
    $method->setAccessible(true);

    return $method->invoke(null);
}

it('lists a newly created warehouse in the Match modal Parts-purchase dropdown', function () {
    $warehouse = Warehouse::create(['name' => 'O Reilly Auto Parts', 'active' => true, 'supplier' => null, 'supplier_code' => null]);

    $options = warehouseSelectOptionsForTest();

    expect($options)->toHaveKey($warehouse->id)
        ->and($options[$warehouse->id])->toBe('O Reilly Auto Parts');
});

it('lists an inactive warehouse too - this picker deliberately does not filter on active', function () {
    $warehouse = Warehouse::create(['name' => 'Retired Supplier', 'active' => false]);

    expect(warehouseSelectOptionsForTest())->toHaveKey($warehouse->id);
});
