<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Last observed state of one external connector. One row per connector key,
 * written by the hourly health sweep (App\Console\Commands\CheckConnectorHealth).
 */
class ConnectorHealth extends Model
{
    protected $table = 'connector_health';

    protected $guarded = ['id'];

    public const OK = 'ok';

    public const DOWN = 'down';

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    public function isDown(): bool
    {
        return $this->status === self::DOWN;
    }

    /**
     * True when the current status still hasn't been announced. Drives the
     * alert so a connector that stays dead doesn't re-post every hour, while a
     * recovery (or a fresh break) always does.
     */
    public function needsAlert(): bool
    {
        return $this->status !== $this->last_alerted_status;
    }
}
