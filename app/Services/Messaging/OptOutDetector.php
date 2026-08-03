<?php

namespace App\Services\Messaging;

/**
 * Detects opt-out intent in inbound SMS. Per current FCC guidance, opt-out must
 * be honored when expressed "in any reasonable manner" — not only an exact
 * "STOP". We err toward opting out (compliance-safe), with two guardrails
 * learned in production: tapback reactions ('Liked "…"') echo OUR outbound
 * message back and carry no customer intent, and everyday words like END/CANCEL
 * only count when they are the entire message ("on our end" and "cancel the
 * appointment" are not opt-outs).
 */
class OptOutDetector
{
    /** Unambiguous keywords — match as a standalone token anywhere ("please STOP"). */
    private const KEYWORDS = [
        'STOP', 'STOPALL', 'UNSUBSCRIBE', 'OPTOUT',
    ];

    /** Everyday words — opt-out only when the whole message is just this word. */
    private const EXACT_KEYWORDS = [
        'END', 'CANCEL', 'QUIT',
    ];

    /** Multi-word phrases — match anywhere in the message. */
    private const PHRASES = [
        'OPT OUT', 'REMOVE ME', 'DO NOT TEXT', "DON'T TEXT ME", 'DONT TEXT ME',
    ];

    public function isOptOut(?string $body): bool
    {
        if (blank($body)) {
            return false;
        }

        // iPhone/RCS tapback reactions quote our own outbound text verbatim —
        // any keyword inside the quotes is ours, not the customer's.
        if ($this->isReaction($body)) {
            return false;
        }

        $normalized = strtoupper(trim($body));
        // Collapse whitespace; keep apostrophes for "DON'T".
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        foreach (self::PHRASES as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        // Tokenize on non-letter boundaries (keep apostrophes inside words).
        $tokens = preg_split("/[^A-Z']+/", $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            if (in_array($token, self::KEYWORDS, true)) {
                return true;
            }
        }

        return count($tokens) === 1 && in_array($tokens[0], self::EXACT_KEYWORDS, true);
    }

    /** A tapback/reaction SMS: 'Liked "…"', 'Loved "…"', 'Reacted 👍 to "…"', etc. */
    private function isReaction(string $body): bool
    {
        return (bool) preg_match(
            '/^\s*(Liked|Loved|Disliked|Laughed at|Emphasized|Questioned|Reacted\s+\S{1,8}\s+to|Removed a\s+\w+\s+from)\s+[“"].*[”"]\s*$/su',
            $body,
        );
    }
}
