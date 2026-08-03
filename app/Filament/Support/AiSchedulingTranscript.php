<?php

namespace App\Filament\Support;

use App\Models\AiSchedulingSession;
use App\Models\AiSchedulingTurn;
use App\Services\AiScheduling\ReplyIntent;
use App\Support\Timezone;
use Carbon\CarbonInterface;

/**
 * Builds the AI scheduling session transcript as chat-style bubbles, oldest
 * first, for `resources/views/filament/pages/ai-scheduling-transcript.blade.php`.
 * Mirrors JobNotesFeed's shape exactly: one static method returning plain
 * display-ready arrays, never Eloquent models handed straight to the view.
 */
class AiSchedulingTranscript
{
    /**
     * @return array<int, array{
     *     id: int, party: string, direction: string, who: string, body: string,
     *     at: string, intent: ?string, confidence: ?float, used_llm: bool,
     *     is_pending: bool, pending_template: ?string
     * }>
     */
    public static function bubbles(AiSchedulingSession $session): array
    {
        return $session->turns()
            ->with(['technician', 'customer'])
            ->get()
            ->reject(fn (AiSchedulingTurn $turn) => self::isSomebodyElsesThread($session, $turn))
            ->map(fn (AiSchedulingTurn $turn) => [
                'id' => $turn->id,
                'party' => $turn->party,
                'direction' => $turn->direction,
                'who' => self::who($turn),
                'body' => (string) $turn->body,
                'at' => self::stamp($turn->created_at),
                'intent' => $turn->intent,
                'confidence' => $turn->confidence,
                'used_llm' => (bool) $turn->used_llm,
                'is_pending' => $turn->isAwaitingApproval(),
                'pending_template' => $turn->pending_payload['template'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * With `customer_mode = off` the AI never texts the customer — dispatch
     * owns that thread by hand — so an inbound customer reply the AI didn't
     * act on is somebody else's conversation, and showing it just buries the
     * tech negotiation this transcript exists to show. AiSchedulingService
     * stops recording those, but sessions that ran before it did still carry
     * them, so the same rule is applied here on the way out.
     *
     * "Acted on" = the two intents handleCustomerReply actually does something
     * about: a confirm, or a counter-offer it retargets the session onto.
     */
    private static function isSomebodyElsesThread(AiSchedulingSession $session, AiSchedulingTurn $turn): bool
    {
        if ($session->customer_mode !== AiSchedulingSession::MODE_OFF) {
            return false;
        }

        if ($turn->party !== AiSchedulingTurn::PARTY_CUSTOMER || $turn->direction !== AiSchedulingTurn::DIRECTION_IN) {
            return false;
        }

        return ! in_array($turn->intent, [ReplyIntent::ACCEPT, ReplyIntent::COUNTER], true);
    }

    private static function who(AiSchedulingTurn $turn): string
    {
        return match ($turn->party) {
            AiSchedulingTurn::PARTY_TECH => $turn->technician?->name ?? 'Technician',
            AiSchedulingTurn::PARTY_CUSTOMER => $turn->customer?->first_name ?? 'Customer',
            default => 'System',
        };
    }

    private static function stamp(?CarbonInterface $at): string
    {
        return $at ? Timezone::toDisplay($at)->format('M j, g:i A') : '';
    }
}
