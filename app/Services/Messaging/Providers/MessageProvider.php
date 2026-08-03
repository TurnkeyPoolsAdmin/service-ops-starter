<?php

namespace App\Services\Messaging\Providers;

interface MessageProvider
{
    /**
     * Send an SMS. $from is the single global sending number for all entities.
     * Implementations must not throw for provider-level rejections — return a
     * failed ProviderResult instead so the worker can record and retry.
     */
    public function send(string $to, string $from, string $body): ProviderResult;
}
