<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single account under a linked Plaid Item (checking, savings, or credit card).
 * Refreshed on every transaction sync — see PullBankTransactions. Balances are in
 * cents; for a credit account `current_balance_cents` is the amount OWED.
 */
class BankAccount extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'current_balance_cents' => 'integer',
            'available_balance_cents' => 'integer',
            'limit_cents' => 'integer',
            'balance_updated_at' => 'datetime',
        ];
    }

    public function plaidItem(): BelongsTo
    {
        return $this->belongsTo(PlaidItem::class);
    }

    /** Cash accounts (checking / savings). */
    public function scopeDepository(Builder $q): Builder
    {
        return $q->where('type', 'depository');
    }

    /** Credit cards / lines of credit — `current_balance_cents` is what's owed. */
    public function scopeCredit(Builder $q): Builder
    {
        return $q->where('type', 'credit');
    }
}
