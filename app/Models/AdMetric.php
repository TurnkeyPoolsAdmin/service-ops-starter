<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdMetric extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        // `date` is intentionally NOT cast to a date: we keep it a plain 'Y-m-d'
        // string so the (entity_id, date) upsert key matches exactly on both
        // SQLite (tests) and MySQL (prod). The Eloquent date cast would append a
        // time component on write, breaking idempotent re-pulls on SQLite.
        return [
            'cost_micros' => 'integer',
            'conversions' => 'float',
            'clicks' => 'integer',
            'impressions' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /** Cost in integer cents (Google reports micros; cents = micros / 10,000). */
    public function getCostCentsAttribute(): int
    {
        return intdiv((int) $this->cost_micros, 10_000);
    }
}
