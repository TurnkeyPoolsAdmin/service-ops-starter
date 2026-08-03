<?php

namespace App\Services\AiScheduling;

/**
 * The result of classifying one SMS reply — from a deterministic fast-path
 * hit or from LlmClassifier's JSON. The negotiation state machine (built in a
 * later phase) branches only on this, never on raw model output, so a bad
 * field from the model degrades to `unclear` here rather than crashing or
 * silently misfiring downstream. See docs/ai-scheduling-spec.md §4.1.
 */
final class ReplyIntent
{
    public const ACCEPT = 'accept';

    public const COUNTER = 'counter';

    public const DECLINE = 'decline';

    public const QUESTION = 'question';

    public const UNCLEAR = 'unclear';

    private const VALID_INTENTS = [self::ACCEPT, self::COUNTER, self::DECLINE, self::QUESTION, self::UNCLEAR];

    private function __construct(
        public readonly string $intent,
        public readonly ?string $date,      // 'Y-m-d', only meaningful for counter
        public readonly ?string $time,      // 'H:i' (24h), only meaningful for counter
        public readonly ?string $asks,      // the tech's question, only meaningful for question
        public readonly float $confidence,  // 0.0-1.0
        public readonly string $reason,
        public readonly bool $usedLlm,
    ) {}

    /** A deterministic fast-path hit ("si", "ok", "listo", ...) — full confidence, no LLM call made. */
    public static function fastAccept(): self
    {
        return new self(self::ACCEPT, null, null, null, 1.0, 'deterministic accept phrase', usedLlm: false);
    }

    /**
     * They didn't write "yes", they tapped 👍 (or the carrier relayed a
     * "Liked ..." for them). Still an accept — but the state machine reads it
     * back to them before booking anything, so the reason is worth keeping
     * distinct on the transcript.
     */
    public static function fastReactionAccept(): self
    {
        return new self(self::ACCEPT, null, null, null, 1.0, 'positive reaction to our offer', usedLlm: false);
    }

    /** A deterministic fast-path hit ("no", "no puedo", ...) — full confidence, no LLM call made. */
    public static function fastDecline(): self
    {
        return new self(self::DECLINE, null, null, null, 1.0, 'deterministic decline phrase', usedLlm: false);
    }

    /** Nothing could classify the reply (no fast-path match, no classifier wired, or the call failed). */
    public static function unclear(string $reason): self
    {
        return new self(self::UNCLEAR, null, null, null, 0.0, $reason, usedLlm: false);
    }

    /**
     * Build from the model's raw decoded JSON. Never throws — a malformed or
     * out-of-range field degrades toward `unclear`/0 confidence rather than
     * failing the whole classification, since the caller already treats low
     * confidence as "escalate to a human" (SchedulingConstraint::admits is
     * what actually judges a counter-offer, never this parsing step).
     */
    public static function fromLlmJson(array $json): self
    {
        $intent = is_string($json['intent'] ?? null) && in_array($json['intent'], self::VALID_INTENTS, true)
            ? $json['intent']
            : self::UNCLEAR;

        $confidence = is_numeric($json['confidence'] ?? null) ? (float) $json['confidence'] : 0.0;
        $confidence = max(0.0, min(1.0, $confidence));

        return new self(
            $intent,
            self::validDateOrNull($json['date'] ?? null),
            self::validTimeOrNull($json['time'] ?? null),
            is_string($json['asks'] ?? null) && $json['asks'] !== '' ? $json['asks'] : null,
            $confidence,
            is_string($json['reason'] ?? null) ? $json['reason'] : '',
            usedLlm: true,
        );
    }

    /** Below config('glass.ai_scheduling.min_confidence') — the caller should escalate rather than act. */
    public function isConfident(): bool
    {
        return $this->confidence >= (float) config('glass.ai_scheduling.min_confidence', 0.6);
    }

    private static function validDateOrNull(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private static function validTimeOrNull(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value) === 1 ? $value : null;
    }
}
