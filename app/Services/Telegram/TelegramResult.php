<?php

namespace App\Services\Telegram;

/**
 * Immutable outcome of a Telegram send — mirrors the SMS ProviderResult shape.
 */
readonly class TelegramResult
{
    public function __construct(
        public bool $success,
        public ?int $messageId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?array $rawResponse = null,
        public bool $suppressed = false,
    ) {}

    public static function ok(?int $messageId, ?array $raw = null): self
    {
        return new self(success: true, messageId: $messageId, rawResponse: $raw);
    }

    /**
     * Nothing was sent, and nothing went wrong — a silent day held the post.
     * Kept apart from failed() so a caller can stay quiet about it instead of
     * reporting an error, and so no "we already alerted" stamp is written
     * (success is still false).
     */
    public static function suppressed(string $reason): self
    {
        return new self(
            success: false,
            errorCode: 'silent_day',
            errorMessage: $reason,
            suppressed: true,
        );
    }

    public static function failed(string $code, ?string $message, ?array $raw = null): self
    {
        return new self(
            success: false,
            errorCode: $code,
            errorMessage: $message,
            rawResponse: $raw,
        );
    }
}
