<?php

namespace App\Models;

use App\Enums\DetectedIntent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'detected_intent' => DetectedIntent::class,
            'processed' => 'boolean',
            'received_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
