<?php

namespace App\Services\Podium;

/**
 * Verifies the Podium webhook signature.
 *
 * Podium sends the hex HMAC-SHA256 digest in the `Podium-Signature` header and a
 * millisecond timestamp in `podium-timestamp`. The signed data is the timestamp,
 * a literal ".", then the raw request body. The shared webhook secret (the value
 * we pass as `secret` when creating the subscription) is the HMAC key, used as-is.
 *
 * Reference: https://docs.podium.com/docs/verifying-webhook-signatures
 */
class PodiumSignatureVerifier
{
    public function verify(?string $providedSignature, ?string $timestamp, string $rawBody, ?string $secret): bool
    {
        if (blank($providedSignature) || blank($timestamp) || blank($secret)) {
            return false;
        }

        $computed = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        // Tolerate an optional "sha256=" prefix some tooling prepends.
        $provided = str_starts_with($providedSignature, 'sha256=')
            ? substr($providedSignature, 7)
            : $providedSignature;

        return hash_equals($computed, $provided);
    }

    /** Helper for tests / tooling: produce a valid signature for a given body. */
    public function sign(string $rawBody, string $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }
}
