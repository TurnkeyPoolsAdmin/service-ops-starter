<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A linked bank login (Plaid Item). One per institution connection — e.g. the
 * single Chase login that exposes both the checking account and the credit card.
 */
class PlaidItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            // Encrypted at rest with the app key, same protection as sensitive Settings.
            'access_token' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    /** The accounts (checking / credit card) this login exposes, with their balances. */
    public function accounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }
}
