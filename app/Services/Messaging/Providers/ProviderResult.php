<?php

namespace App\Services\Messaging\Providers;

/**
 * Outcome of a provider send attempt. Never throws for "message rejected" —
 * that is a failed() result the worker records; exceptions are reserved for
 * unexpected transport errors.
 */
class ProviderResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly array $rawResponse = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function ok(?string $providerMessageId, array $rawResponse = []): self
    {
        return new self(true, $providerMessageId, $rawResponse);
    }

    public static function failed(string $errorCode, string $errorMessage, array $rawResponse = []): self
    {
        return new self(false, null, $rawResponse, $errorCode, $errorMessage);
    }
}
