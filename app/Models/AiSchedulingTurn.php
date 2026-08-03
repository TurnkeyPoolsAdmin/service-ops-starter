<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the AI scheduling transcript — an outbound text, an inbound
 * reply, or a system note — plus the raw classification and LLM cost when one
 * was involved. See docs/ai-scheduling-spec.md §2.3.
 */
class AiSchedulingTurn extends Model
{
    public const PARTY_TECH = 'tech';

    public const PARTY_CUSTOMER = 'customer';

    public const PARTY_SYSTEM = 'system';

    public const DIRECTION_OUT = 'out';

    public const DIRECTION_IN = 'in';

    public const DIRECTION_NOTE = 'note';

    /** Only set on an outbound composed in "approve each" mode — see the migration. */
    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_SKIPPED = 'skipped';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'classification' => 'array',
            'pending_payload' => 'array',
            'confidence' => 'float',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_micro' => 'integer',
            'used_llm' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function isAwaitingApproval(): bool
    {
        return $this->approval_status === self::APPROVAL_PENDING;
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiSchedulingSession::class, 'session_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(AiSchedulingAttempt::class, 'attempt_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ScheduledMessage::class);
    }

    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(InboundMessage::class);
    }
}
