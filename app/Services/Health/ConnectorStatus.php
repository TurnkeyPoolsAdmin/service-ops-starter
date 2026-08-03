<?php

namespace App\Services\Health;

/** One connector's probe result. */
class ConnectorStatus
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $healthy,
        public ?string $error = null,
        public ?string $fixHint = null,
    ) {}

    public static function ok(string $key, string $label): self
    {
        return new self($key, $label, true);
    }

    public static function down(string $key, string $label, string $error, ?string $fixHint = null): self
    {
        return new self($key, $label, false, $error, $fixHint);
    }
}
