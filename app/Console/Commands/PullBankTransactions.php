<?php

namespace App\Console\Commands;

use App\Enums\IssueType;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Issue;
use App\Models\PlaidItem;
use App\Services\Plaid\PlaidClient;
use Illuminate\Console\Command;

/**
 * Pull the latest bank/credit transactions for every linked Plaid Item into the
 * bank_transactions table. Scheduled daily (see routes/console.php).
 *
 * Uses Plaid's incremental /transactions/sync: each Item carries a cursor, so a
 * run only fetches what changed since last time. The upsert on plaid_transaction_id
 * makes it idempotent — a pending charge that later posts arrives as a "modified"
 * row and overwrites the pending one rather than duplicating.
 */
class PullBankTransactions extends Command
{
    protected $signature = 'bank:pull-transactions
        {--item= : Limit to a single Plaid item_id}';

    protected $description = 'Sync latest Plaid bank/credit transactions into bank_transactions';

    public function handle(PlaidClient $plaid): int
    {
        if (! $plaid->configured()) {
            $this->warn('Plaid is not configured. Add credentials in Admin > Integrations & API Keys.');

            return self::SUCCESS;
        }

        $items = PlaidItem::query()
            ->when($this->option('item'), fn ($q) => $q->where('item_id', $this->option('item')))
            ->get();

        if ($items->isEmpty()) {
            $this->warn('No linked Plaid items. Click "Connect Chase" in Admin > Integrations & API Keys.');

            return self::SUCCESS;
        }

        $grandTotal = 0;

        foreach ($items as $item) {
            $changed = $this->syncItem($plaid, $item);
            $grandTotal += $changed;
            $this->line(sprintf('%s: %d transaction(s) added/updated', $item->institution_name ?: $item->item_id, $changed));
        }

        $this->info(sprintf('Synced %d transaction change(s) across %d item(s).', $grandTotal, $items->count()));

        return self::SUCCESS;
    }

    /** Drain all sync pages for one Item; returns how many rows were written. */
    private function syncItem(PlaidClient $plaid, PlaidItem $item): int
    {
        $cursor = $item->cursor;
        $written = 0;

        do {
            $resp = $plaid->syncTransactions($item->access_token, $cursor);

            if ($resp === null) {
                $item->update(['status' => 'error', 'last_error' => 'transactions/sync failed — see logs']);

                return $written;
            }

            $accounts = $this->accountMap($resp['accounts'] ?? []);

            foreach (array_merge($resp['added'] ?? [], $resp['modified'] ?? []) as $txn) {
                $this->upsertTransaction($item, $txn, $accounts);
                $written++;
            }

            foreach ($resp['removed'] ?? [] as $removed) {
                BankTransaction::query()
                    ->where('plaid_transaction_id', $removed['transaction_id'] ?? '')
                    ->delete();
            }

            $cursor = $resp['next_cursor'] ?? $cursor;
            $hasMore = (bool) ($resp['has_more'] ?? false);
        } while ($hasMore);

        // Refresh account balances explicitly — see refreshBalances (the sync
        // response's accounts array is empty once the cursor is caught up).
        $this->refreshBalances($plaid, $item);

        $item->update([
            'cursor' => $cursor,
            'status' => 'active',
            'last_error' => null,
            'last_synced_at' => now(),
        ]);

        return $written;
    }

    /**
     * Persist current per-account balances so the ledger can show account health.
     * Pulled from /accounts/get (always returns all accounts), unlike the transaction
     * sync whose accounts array only appears alongside changes. On a fetch error we
     * leave the last-known balances untouched rather than wiping them.
     */
    private function refreshBalances(PlaidClient $plaid, PlaidItem $item): void
    {
        $accounts = $plaid->getBalances($item->access_token);

        if ($accounts === null) {
            return;
        }

        $this->upsertBalances($item, $accounts);
    }

    /**
     * Upsert the given accounts array (Plaid /accounts shape) into bank_accounts.
     * Plaid returns balances in dollars; for a credit account `current` is the amount
     * owed (positive), for a depository account it's the cash balance.
     */
    private function upsertBalances(PlaidItem $item, array $accounts): void
    {
        foreach ($accounts as $account) {
            $accountId = $account['account_id'] ?? null;

            if (blank($accountId)) {
                continue;
            }

            $balances = $account['balances'] ?? [];

            BankAccount::query()->updateOrCreate(
                ['plaid_account_id' => $accountId],
                [
                    'plaid_item_id' => $item->id,
                    'name' => $account['official_name'] ?? $account['name'] ?? null,
                    'mask' => $account['mask'] ?? null,
                    'type' => $account['type'] ?? null,
                    'subtype' => $account['subtype'] ?? null,
                    'current_balance_cents' => $this->toCents($balances['current'] ?? null),
                    'available_balance_cents' => $this->toCents($balances['available'] ?? null),
                    'limit_cents' => $this->toCents($balances['limit'] ?? null),
                    'iso_currency_code' => $balances['iso_currency_code'] ?? 'USD',
                    'balance_updated_at' => now(),
                ],
            );
        }
    }

    private function toCents(int|float|null $dollars): ?int
    {
        return $dollars === null ? null : (int) round(((float) $dollars) * 100);
    }

    /** account_id => ['name' => ..., 'mask' => ...] for denormalising onto each row. */
    private function accountMap(array $accounts): array
    {
        $map = [];

        foreach ($accounts as $account) {
            $map[$account['account_id'] ?? ''] = [
                'name' => $account['official_name'] ?? $account['name'] ?? null,
                'mask' => $account['mask'] ?? null,
            ];
        }

        return $map;
    }

    private function upsertTransaction(PlaidItem $item, array $txn, array $accounts): void
    {
        $accountId = $txn['account_id'] ?? '';
        $account = $accounts[$accountId] ?? ['name' => null, 'mask' => null];

        $incoming = [
            'amount_cents' => $this->toCents($txn['amount'] ?? 0),
            'date' => $txn['date'] ?? null,
            'authorized_date' => $txn['authorized_date'] ?? null,
        ];

        $existing = BankTransaction::where('plaid_transaction_id', $txn['transaction_id'])->first();

        if ($existing !== null && in_array($existing->match_status, [BankTransaction::STATUS_MATCHED, BankTransaction::STATUS_SUGGESTED], true)) {
            $this->flagIfKeyFieldsChanged($existing, $incoming);
        }

        BankTransaction::query()->updateOrCreate(
            ['plaid_transaction_id' => $txn['transaction_id']],
            [
                'plaid_item_id' => $item->id,
                'plaid_account_id' => $accountId,
                'account_name' => $account['name'],
                'account_mask' => $account['mask'],
                'amount_cents' => $incoming['amount_cents'],
                'iso_currency_code' => $txn['iso_currency_code'] ?? 'USD',
                'date' => $incoming['date'],
                'authorized_date' => $incoming['authorized_date'],
                'name' => $txn['name'] ?? '(no description)',
                'merchant_name' => $txn['merchant_name'] ?? null,
                'category' => $txn['personal_finance_category']['primary'] ?? null,
                'payment_channel' => $txn['payment_channel'] ?? null,
                'pending' => (bool) ($txn['pending'] ?? false),
                'synced_at' => now(),
            ],
        );
    }

    /**
     * Plaid's upsert can silently rewrite amount_cents/date/authorized_date on
     * a transaction that's already matched or suggested — the exact gap that
     * let five genuinely-correct-when-made tech-payout matches look wrong in
     * hindsight (2026-07-31 investigation: every one of them was exact at
     * creation; a later sync rewrote one of these fields on the transaction
     * afterward, with nothing catching it). Flags it for human review via an
     * Issue — deliberately never auto-unmatches or auto-reverses, since the
     * match itself is often still correct; only a human reviewing the specific
     * case can tell which it is.
     */
    private function flagIfKeyFieldsChanged(BankTransaction $existing, array $incoming): void
    {
        $changed = [];
        foreach (['amount_cents', 'date', 'authorized_date'] as $field) {
            if ((string) $existing->{$field} !== (string) $incoming[$field]) {
                $changed[$field] = ['old' => $existing->{$field}, 'new' => $incoming[$field]];
            }
        }

        if ($changed === []) {
            return;
        }

        Issue::raise(IssueType::MatchedTransactionFieldsChanged, $existing, [
            'txn_description' => $existing->name,
            'match_status' => $existing->match_status,
            'changed_fields' => implode(', ', array_keys($changed)),
            'old_amount_cents' => $changed['amount_cents']['old'] ?? null,
            'new_amount_cents' => $changed['amount_cents']['new'] ?? null,
            'old_date' => $changed['date']['old'] ?? null,
            'new_date' => $changed['date']['new'] ?? null,
            'old_authorized_date' => $changed['authorized_date']['old'] ?? null,
            'new_authorized_date' => $changed['authorized_date']['new'] ?? null,
            'match_ids' => $existing->matches()->pluck('id')->implode(', '),
        ]);
    }
}
