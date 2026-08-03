<?php

use App\Enums\BankMatchKind;
use App\Filament\Pages\Issues;
use App\Filament\Pages\PartsInvoices;
use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Models\BankTransaction;
use App\Models\Employee;
use App\Models\PlaidItem;
use App\Models\VendorDocument;
use Database\Seeders\RoleSeeder;

/**
 * Covers the PART 2 UI surface that isn't exercised by the pure-service tests
 * (MygrantReconciliationTest / MygrantLineMatchingTest): the Parts Invoices
 * page renders, the new "Vendor invoice" kind on the Bank Transactions
 * ledger's manual Match modal saves and stamps charge_matched_at, and the two
 * new condition-shaped Issues subviews render.
 */
beforeEach(function () {
    test()->seed(RoleSeeder::class);
    test()->admin = Employee::create([
        'name' => 'Admin', 'email' => 'vendor-ledger-admin@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    test()->admin->assignRole('super_admin');
});

it('renders the Parts Invoices page for an admin', function () {
    Livewire\Livewire::actingAs(test()->admin)
        ->test(PartsInvoices::class)
        ->assertSuccessful();
});

/**
 * 2026-07-30, root-caused: IGC's header-action modals ("Upload invoice
 * history") used to fail to visually render while on the IGC tab. The
 * original theory (and workaround) was that Livewire's browser-side morph
 * needed the "Lines to match" table markup physically present every
 * request to keep working, so the table was force-rendered CSS-hidden
 * rather than @if-omitted on non-Mygrant tabs.
 *
 * The real cause: Filament's shared action-modals partial
 * (<x-filament-actions::modals />) only actually renders from whichever of
 * several possible spots Blade evaluates FIRST — one of them being the
 * table component's own root div. Because this page renders
 * {{ $this->table }} directly in its body, the table's copy won that race
 * and the modal ended up physically nested inside the table's own DOM
 * subtree. Any wrapper used to hide the table then also hid the modal — a
 * position:fixed descendant still gets clipped by an ancestor's
 * overflow/clip in Chromium, even though it escapes that ancestor's
 * layout offset. Fixed by rendering <x-filament-actions::modals /> once,
 * explicitly, unwrapped, at the top of parts-invoices.blade.php, so it
 * always wins the race regardless of which tab (or whether the table
 * renders at all). The table itself went back to simple @if rendering.
 *
 * These assert the real invariant: the IGC action modal actually renders
 * its content while the table is legitimately absent from that tab, and
 * the table still renders normally back on Mygrant.
 */
it('renders the IGC upload-invoice-history action modal even though the table is hidden on that tab', function () {
    $component = Livewire\Livewire::actingAs(test()->admin)->test(PartsInvoices::class);

    $component->call('switchSupplier', 'igc')->assertSuccessful();
    $component->assertDontSee('Lines to match');

    // Livewire::test()->html() doesn't render mounted-action modal content
    // the way a live browser round-trip does (confirmed against a
    // known-working action too), so this checks the mounted-action state
    // directly rather than asserting the field label is in the HTML.
    $component->mountAction('uploadIgcInvoiceHistory')->assertSuccessful();
    expect($component->get('mountedActions.0.name'))->toBe('uploadIgcInvoiceHistory');
});

it('still renders the Lines to match table on the Mygrant tab', function () {
    Livewire\Livewire::actingAs(test()->admin)
        ->test(PartsInvoices::class)
        ->assertSee('Lines to match');
});

/**
 * The real regression guard for the visual bug: Livewire::test() can't see
 * client-side paint, but it CAN see raw HTML ordering. The shared
 * action-modals partial must render as a preceding sibling of the "Lines
 * to match" table section, not nested after/inside it — that ordering is
 * exactly what let the table's own internal copy of the partial "win the
 * race" and get embedded inside the table's DOM subtree in the original
 * bug. If this partial ever moves back to after the table (or the table
 * gets wrapped again without this explicit call preceding it), this fails.
 */
it('renders the shared action-modals partial before the Lines to match table', function () {
    $html = Livewire\Livewire::actingAs(test()->admin)->test(PartsInvoices::class)->html();

    $modalsPos = strpos($html, 'action-modals');
    $tablePos = strpos($html, 'Lines to match');

    expect($modalsPos)->not->toBeFalse()
        ->and($tablePos)->not->toBeFalse()
        ->and($modalsPos)->toBeLessThan($tablePos);
});

it('renders the two new Mygrant subviews on the Issues page', function () {
    Livewire\Livewire::actingAs(test()->admin)
        ->test(Issues::class, ['subview' => 'mygrant_charge'])
        ->assertSuccessful();

    Livewire\Livewire::actingAs(test()->admin)
        ->test(Issues::class, ['subview' => 'mygrant_line'])
        ->assertSuccessful();
});

it('the ledger Match modal offers the Vendor invoice kind and stamps the document charged', function () {
    $item = PlaidItem::create(['item_id' => 'ledger-item', 'access_token' => 'x', 'institution_name' => 'Chase']);
    $doc = VendorDocument::create([
        'supplier' => 'mygrant', 'type' => 'invoice', 'document_number' => 'I999-0',
        'document_date' => '2026-07-01', 'cut_date' => '2026-07-01', 'settle_date' => '2026-07-01',
        'net_amount_cents' => 10000, 'line_count' => 1, 'source' => 'order_history',
    ]);
    $txn = BankTransaction::create([
        'plaid_item_id' => $item->id, 'plaid_transaction_id' => 'ledger-txn-1', 'plaid_account_id' => 'acc-1',
        'amount_cents' => 10000, 'date' => '2026-07-01', 'name' => 'MYGRANT GLASS CO', 'merchant_name' => 'Mygrant Glass', 'pending' => false,
    ]);

    Livewire\Livewire::actingAs(test()->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('match', $txn, data: [
            'kind' => BankMatchKind::VendorInvoice->value,
            'vendor_document_ids' => [$doc->id],
        ])
        ->assertHasNoTableActionErrors();

    expect($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($txn->matches->first()->kind)->toBe(BankMatchKind::VendorInvoice)
        ->and($doc->refresh()->charge_matched_at)->not->toBeNull();
});

it('unmatching a vendor-invoice match frees the document back to open', function () {
    $item = PlaidItem::create(['item_id' => 'ledger-item-2', 'access_token' => 'x', 'institution_name' => 'Chase']);
    $doc = VendorDocument::create([
        'supplier' => 'mygrant', 'type' => 'invoice', 'document_number' => 'I998-0',
        'document_date' => '2026-07-01', 'cut_date' => '2026-07-01', 'settle_date' => '2026-07-01',
        'net_amount_cents' => 10000, 'line_count' => 1, 'source' => 'order_history',
    ]);
    $txn = BankTransaction::create([
        'plaid_item_id' => $item->id, 'plaid_transaction_id' => 'ledger-txn-2', 'plaid_account_id' => 'acc-1',
        'amount_cents' => 10000, 'date' => '2026-07-01', 'name' => 'MYGRANT GLASS CO', 'merchant_name' => 'Mygrant Glass', 'pending' => false,
    ]);

    Livewire\Livewire::actingAs(test()->admin)
        ->test(ListBankTransactions::class)
        ->callTableAction('match', $txn, data: [
            'kind' => BankMatchKind::VendorInvoice->value,
            'vendor_document_ids' => [$doc->id],
        ])
        ->assertHasNoTableActionErrors();

    expect($doc->refresh()->charge_matched_at)->not->toBeNull();

    Livewire\Livewire::actingAs(test()->admin)
        ->test(ListBankTransactions::class)
        ->set('activeTab', 'all')
        ->callTableAction('unmatch', $txn)
        ->assertHasNoTableActionErrors();

    expect($doc->refresh()->charge_matched_at)->toBeNull();
});
